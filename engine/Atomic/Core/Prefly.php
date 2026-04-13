<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Traits\Singleton;

class Prefly
{
    use Singleton;

    private const MIN_PHP_VERSION = '8.1.0';
    private const REQUIRED_EXTENSIONS = [
        'json',
        'session',
        'mbstring',
        'fileinfo',
        'pdo',
        'pdo_mysql',
        'curl'
    ];

    private function __construct() {  }

    public function is_php_version_compatible( string $required ) : bool
    {
        return empty( $required ) || version_compare( PHP_VERSION, $required, '>=' );
    }

    public function is_extension_loaded( string $extension ) : bool
    {
        return empty( $extension ) || extension_loaded( $extension );
    }

    public function is_function_available( string $function ) : bool
    {
        return empty( $function ) || function_exists( $function );
    }

    public function is_class_available( string $class ) : bool
    {
        return empty( $class ) || class_exists( $class );
    }

    public function check_environment() : array
    {
        $results = [
            'php_version' => [
                'required' => self::MIN_PHP_VERSION,
                'current'  => PHP_VERSION,
                'status'   => $this->is_php_version_compatible( self::MIN_PHP_VERSION ),
            ],
            'extensions' => [],
        ];

        foreach ( self::REQUIRED_EXTENSIONS as $ext ) {
            $results['extensions'][$ext] = [
                'required' => true,
                'status'   => $this->is_extension_loaded( $ext ),
            ];
        }

        return $results;
    }

    public function all_checks_passed() : bool
    {
        $checks = $this->check_environment();
        
        if ( isset( $checks['php_version']['status'] ) && $checks['php_version']['status'] === false ) {
            return false;
        }
        if ( isset( $checks['extensions'] ) && is_array( $checks['extensions'] ) ) {
            foreach ( $checks['extensions'] as $ext_check ) {
                if ( isset( $ext_check['status'] ) && $ext_check['status'] === false ) {
                    return false;
                }
            }
        }
        return true;
    }
}
