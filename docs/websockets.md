## WebSockets ##

Atomic ships a reusable WebSocket server base class in `Engine\Atomic\WebSockets\Server` and a thin connection wrapper in `Engine\Atomic\WebSockets\Connection`.

### Current scope

The repository includes:

- an abstract server base class
- a connection wrapper
- a single-shot test client utility class

The repository does **not** include a concrete application WebSocket server by default.

### Base server API

Required methods in subclasses:

- `setup(): void`
- `on_connect(Connection $conn): void`
- `on_message(Connection $conn, string $data, int $op): void`
- `on_disconnect(Connection $conn): void`

Optional hook:

- `on_worker_start(): void`

### Runtime behavior

When `run()` is called:

1. `worker_count` must be at least `1`, otherwise an `InvalidArgumentException` is thrown.
2. If the listen string starts with `tcp://`, it is rewritten to `websocket://`.
3. A Workerman `Worker` is created for that listener.
4. `LOGS/ws` is used for pid and log files. `LOGS` must be configured.
5. `setup()` is executed before Workerman callbacks are assigned.
6. On worker start, Redis async client initialization runs, then `on_worker_start()`, then optional pubsub startup.
7. The server runs in foreground or daemon mode through `Workerman\Worker::runAll()`.

Generated filenames:

- `workerman.<lowercased-server-class>.pid`
- `workerman.<lowercased-server-class>.log`

Example class tag:

`App\WebSockets\JobsServer` -> `workerman.app.websockets.jobsserver.*`

### Connection wrapper

`Connection` wraps Workerman `TcpConnection` and exposes:

- `id(): string`
- `socket_int(): int`
- `send(string $data): bool`
- `close(): void`

Use the wrapper in your hooks instead of accessing the raw Workerman connection directly.

### Task mapping

The base server keeps two in-memory structures:

- `task_id -> socket_int`
- `socket_int -> task_id[]`

Helpers available to subclasses:

- `subscribe_to_channel(string $channel): void`
- `map_task(string $task_id, int $socket_int): void`
- `get_socket_tasks(int $socket_int): array`
- `unmap_task(string $task_id): void`

### Redis pubsub bridge

If `subscribe_to_channel(...)` was called in `setup()`, `run()` will start a Redis pubsub listener on worker start.

Behavior of `start_pubsub(...)`:

1. Connects to Redis using `REDIS.host` and `REDIS.port`.
2. Subscribes to the configured channel.
3. Decodes each payload as JSON.
4. Reads `task_id`.
5. Finds the mapped socket.
6. Sends the original payload back to that socket.
7. Unmaps the task.

Expected payload shape:

```json
{
  "task_id": "uuid-or-id",
  "status": "completed",
  "data": {}
}
```

If the payload is not valid JSON or does not contain `task_id`, it is ignored.

### Minimal server example

```php
<?php
declare(strict_types=1);

namespace App\WebSockets;

use Engine\Atomic\WebSockets\Connection;
use Engine\Atomic\WebSockets\Server;

final class JobsServer extends Server
{
    protected function setup(): void
    {
        $this->subscribe_to_channel('queue:results');
    }

    protected function on_connect(Connection $conn): void
    {
        $conn->send(json_encode(['type' => 'welcome', 'id' => $conn->id()]));
    }

    protected function on_message(Connection $conn, string $data, int $op): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg) || empty($msg['task_id'])) {
            $conn->send(json_encode(['error' => 'task_id required']));
            return;
        }

        $this->map_task((string)$msg['task_id'], $conn->socket_int());
    }

    protected function on_disconnect(Connection $conn): void
    {
        foreach ($this->get_socket_tasks($conn->socket_int()) as $taskId) {
            $this->unmap_task($taskId);
        }
    }
}
```

### Operational notes

- `REDIS` defaults to `127.0.0.1:6379` when not configured.
- `LOGS` is mandatory for both the server and the bundled test client.
- Daemon mode rewrites Workerman argv to `start -d`.
