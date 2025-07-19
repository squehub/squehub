<?php
// app/core/Exceptions/CustomPrettyPageHandler.php

namespace App\Core\Exceptions;

use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\Handler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Exception\Formatter;

class CustomPrettyPageHandler extends PrettyPageHandler
{
    /**
     * Handle the exception rendering process.
     * This custom handler overrides the default PrettyPageHandler
     * to customize layout, styling, and how debug data is shown.
     */
    public function handle()
    {
        // Debug helper: Write to file so we can confirm this handler is being used
        file_put_contents(__DIR__ . '/whoops-debug.log', "CustomPrettyPageHandler used\n", FILE_APPEND);

        if (!$this->handleUnconditionally()) {
            if (PHP_SAPI === 'cli') {
                return Handler::DONE;
            }
        }

        $templateFile = $this->getResource("views/layout.html.php");

        $vars = [
            "page_title" => $this->getPageTitle(),
            "stylesheet" => file_get_contents($this->getResource("css/whoops.base.css")),
            "zepto"      => file_get_contents($this->getResource("js/zepto.min.js")),
            "prismJs"    => file_get_contents($this->getResource("js/prism.js")),
            "prismCss"   => file_get_contents($this->getResource("css/prism.css")),
            "clipboard"  => file_get_contents($this->getResource("js/clipboard.min.js")),
            "javascript" => file_get_contents($this->getResource("js/whoops.base.js")),

            "header"     => $this->getResource("views/header.html.php"),
            "header_outer" => $this->getResource("views/header_outer.html.php"),
            "frame_list" => $this->getResource("views/frame_list.html.php"),
            "frames_description" => $this->getResource("views/frames_description.html.php"),
            "frames_container" => $this->getResource("views/frames_container.html.php"),
            "panel_details" => $this->getResource("views/panel_details.html.php"),
            "panel_details_outer" => $this->getResource("views/panel_details_outer.html.php"),
            "panel_left" => $this->getResource("views/panel_left.html.php"),
            "panel_left_outer" => $this->getResource("views/panel_left_outer.html.php"),
            "frame_code" => $this->getResource("views/frame_code.html.php"),
            "env_details" => $this->getResource("views/env_details.html.php"),

            "title" => $this->getPageTitle(),
            "name" => explode("\\", $this->getInspector()->getExceptionName()),
            "message" => $this->getInspector()->getExceptionMessage(),
            "previousMessages" => $this->getInspector()->getPreviousExceptionMessages(),
            "docref_url" => $this->getInspector()->getExceptionDocrefUrl(),
            "code" => $this->getExceptionCode(),
            "previousCodes" => $this->getInspector()->getPreviousExceptionCodes(),
            "plain_exception" => Formatter::formatExceptionPlain($this->getInspector()),
            "frames" => $this->getExceptionFrames(),
            "has_frames" => !!count($this->getExceptionFrames()),
            "handler" => $this,
            "handlers" => $this->getRun()->getHandlers(),

            "active_frames_tab" => count($this->getExceptionFrames()) && $this->getExceptionFrames()->offsetGet(0)->isApplication() ? 'application' : 'all',
            "has_frames_tabs" => $this->getApplicationPaths(),

            // Use custom maskArray to filter sensitive data
            "tables" => [
                "GET Data"            => $this->maskArray($_GET),
                "POST Data"           => $this->maskArray($_POST),
                "Files"               => isset($_FILES) ? $this->maskArray($_FILES) : [],
                "Cookies"             => $this->maskArray($_COOKIE),
                "Session"             => isset($_SESSION) ? $this->maskArray($_SESSION) : [],
                "Server/Request Data" => $this->maskArray($_SERVER),
            ],
        ];

        $plainTextHandler = new PlainTextHandler();
        $plainTextHandler->setRun($this->getRun());
        $plainTextHandler->setException($this->getException());
        $plainTextHandler->setInspector($this->getInspector());
        $vars["preface"] = "<!--\n\n\n" . $this->templateHelper->escape($plainTextHandler->generateResponse()) . "\n\n\n\n\n\n\n\n\n\n\n-->";

        $this->templateHelper->setVariables($vars);
        $this->templateHelper->render($templateFile);

        return Handler::QUIT;
    }

    /**
     * Recursively masks sensitive data in arrays to protect secrets.
     *
     * @param array $array
     * @param string $context Optional, for future context-based masking
     * @return array
     */
    private function maskArray(array $array, string $context = ''): array
    {
        $masked = [];
        $sensitiveKeys = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'auth', 'authorization'];

        foreach ($array as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $masked[$key] = '***';
            } elseif (is_array($value)) {
                $masked[$key] = $this->maskArray($value, $context);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
