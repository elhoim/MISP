<?php
/** Minimal framework doubles for loading Server without the CakePHP bootstrap. */

if (!class_exists('AppModel', false)) {
    class AppModel
    {
        public function __get($name)
        {
            return null;
        }

        protected function loadLog()
        {
            return new class {
                public function createLogEntry(...$args) {}
            };
        }
    }
}

if (!class_exists('SystemSetting', false)) {
    class SystemSetting
    {
        const ALLOWED_CATEGORIES = ['MISP'];

        public static function isSensitive($setting)
        {
            return false;
        }
    }
}

if (!function_exists('__')) {
    function __($text, ...$args)
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}
