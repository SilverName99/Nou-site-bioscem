<?php

declare(strict_types=1);

namespace App\Support;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        $templateFile = __DIR__ . '/../../views/' . $template . '.php';
        $layoutFile = __DIR__ . '/../../views/' . $layout . '.php';

        if (!is_file($templateFile) || !is_file($layoutFile)) {
            http_response_code(500);
            echo 'Template not found.';
            return;
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $templateFile;
        $content = ob_get_clean();

        require $layoutFile;
    }
}
