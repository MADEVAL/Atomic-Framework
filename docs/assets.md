## Assets ##

```php
// CSS
enqueue_style('theme', '~css/style.css'); // ~ => URL theme
enqueue_style('w3', 'https://www.w3schools.com/w3css/5/w3.css');

// JS header
enqueue_script('jquery', '/public/js/jquery-3.7.1.min.js', [], '3.7.1', false);

// JS footer (defer/type)
enqueue_script('app', '~js/app.js', ['jquery'], null, true, ['defer' => true]);
set_script_attrs('app', ['type' => 'module']); 

add_inline_style('theme', ':root{--brand:#ff3b30;}');
add_inline_script('jquery', 'console.log("jQ ready");', 'header');
add_inline_script('app', 'console.log("App booted", window.AtomicData?.app);', 'footer');

localize_script('app', [
    'ajaxUrl' => \Engine\Atomic\Core\Methods::instance()->get_publicUrl() . '/api',
    'i18n'    => [
        'save'   => __('default.Save', [], 'default'),
        'cancel' => __('default.Cancel', [], 'default'),
    ],
    'nonce'  => create_nonce('api_nonce', 3600),
]);
```

```php
enqueue_script('map',  '~js/map.js',  [], null, true, ['defer'=>true]);
localize_script('map', [
    'apiBase' => '/api/v1',
    'markers' => [
        ['lat'=>50.45,'lng'=>30.52,'title'=>'Kyiv'],
        ['lat'=>52.37,'lng'=>4.89,'title'=>'Amsterdam'],
    ],
    'zoom' => 6,
], 'mapData'); 

enqueue_script('chat', '~js/chat.js', [], null, true, ['defer'=>true]);
localize_script('chat', [
    'userId'   => 12345,
    'token'    => 'abcdef-123456',
    'features' => ['rooms'=>true,'attachments'=>false],
    'wsUrl'    => 'wss://chat.example.com/socket',
], 'chatData'); 
```

```js
(function(cfg){
  // cfg = window.mapData
  const apiBase = cfg.apiBase || '/api';
  const zoom = Number(cfg.zoom ?? 5);
  console.log('Map:', apiBase, zoom, cfg.markers);
})(window.mapData || {});

(function(cfg){
  // cfg = window.chatData
  console.log('Chat user:', cfg.userId, 'features:', cfg.features);
})(window.chatData || {});

```

```php
enqueue_script('jquery', '/public/js/jquery-3.7.1.min.js', [], '3.7.1', false);
enqueue_script('chat',   '~js/chat.js', ['jquery'], null, true);
localize_script('chat', ['roomId'=>42, 'welcome'=>'Hi!'], 'chatData');
```

```js
(function($, cfg){
  // cfg = window.chatData
  $(function(){
    console.log('Room:', cfg.roomId, 'msg:', cfg.welcome);
  });
})(jQuery, window.chatData || {});

```

```php
$block_js_data = [
    'hasCountdown'  => ($wait > 0),
    'waitSeconds'   => $wait,
    'accessBlocked' => 'Access has been blocked',
    'secondsLeft'   => 'Seconds left until the unlock:',
    'reasonView'    => $reason_view,
    'reasonText'    => $reason_view ? ('REASON_CODE: ['.$this->reason_for_action.'] / CHECK_RESULT: ['.$this->result_of_action.']') : '',
];

enqueue_script('bbcs-block-script', '~js/block.js', [], '1.0.0', true, ['defer'=>true]);

if (!empty($block_js_data)) {
    localize_script('bbcs-block-script', $block_js_data, 'bbcsBlockData');
}
```

```js
var data = window.bbcsBlockData || {};
var infos = document.querySelectorAll(".container .info");
var target = infos[infos.length - 1];
target.textContent = "";

if (data.hasCountdown && data.waitSeconds > 0) {
  var wait = parseInt(data.waitSeconds, 10) || 0;
  var wrapper = document.createElement("div");
  wrapper.innerHTML =
    "<center><h1 class='info info-block'>" +
    String(data.accessBlocked) +
    "</h1>" +
    "<h5 class='block-string'>" +
    String(data.secondsLeft) +
    ' <span id="countdownTimer"><b>' +
    wait +
    "</b></span></h5></center>";
  target.appendChild(wrapper);

  var endTime = Date.now() + wait * 1000;

  function updateCounter() {
    var timeLeft = Math.ceil((endTime - Date.now()) / 1000);
    var t = document.getElementById("countdownTimer");
    if (t) {
      var b = t.querySelector("b");
      if (b) b.textContent = timeLeft > 0 ? timeLeft : 0;
      else t.innerHTML = "<b>" + (timeLeft > 0 ? timeLeft : 0) + "</b>";
    }
    if (timeLeft <= 0) { location.reload(); return; }
    requestAnimationFrame(updateCounter);
  }
  requestAnimationFrame(updateCounter);
}

if (data.reasonView && data.reasonText) {
  target.appendChild(document.createElement("br"));
  target.appendChild(document.createTextNode(String(data.reasonText)));
}
```