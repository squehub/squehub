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
        // ✅ Debug helper: Write to file so we can confirm this handler is being used
        file_put_contents(__DIR__ . '/whoops-debug.log', "CustomPrettyPageHandler used\n", FILE_APPEND);

        // If the handler shouldn't run under current context (e.g., not web), exit
        if (!$this->handleUnconditionally()) {
            if (PHP_SAPI === 'cli') {
                return Handler::DONE;
            }
        }

        // ✅ Load custom error layout template
        $templateFile = $this->getResource("views/layout.html.php");

        // ✅ Prepare data and assets to pass to the template
        $vars = [
            "page_title" => $this->getPageTitle(),
            "stylesheet" => file_get_contents($this->getResource("css/whoops.base.css")),
            "zepto"      => file_get_contents($this->getResource("js/zepto.min.js")),
            "prismJs"    => file_get_contents($this->getResource("js/prism.js")),
            "prismCss"   => file_get_contents($this->getResource("css/prism.css")),
            "clipboard"  => file_get_contents($this->getResource("js/clipboard.min.js")),
            "javascript" => file_get_contents($this->getResource("js/whoops.base.js")),

            // ✅ Include view fragments
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

            // ✅ Exception details
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

            // ✅ Determine which tab to show by default (application vs all frames)
            "active_frames_tab" => count($this->getExceptionFrames()) && $this->getExceptionFrames()->offsetGet(0)->isApplication() ? 'application' : 'all',
            "has_frames_tabs" => $this->getApplicationPaths(),

            // ✅ Filtered input/output variables for safety
            "tables" => [
                "GET Data"            => $this->masked($_GET, '_GET'),
                "POST Data"           => $this->masked($_POST, '_POST'),
                "Files"               => isset($_FILES) ? $this->masked($_FILES, '_FILES') : [],
                "Cookies"             => $this->masked($_COOKIE, '_COOKIE'),
                "Session"             => isset($_SESSION) ? $this->masked($_SESSION, '_SESSION') : [],
                "Server/Request Data" => $this->masked($_SERVER, '_SERVER'),
                // "Environment Variables" => $this->masked($_ENV, '_ENV'), // ❌ Removed for security
            ],
        ];

        // ✅ Optional: Embed a plain-text version of the exception inside HTML comments
        $plainTextHandler = new PlainTextHandler();
        $plainTextHandler->setRun($this->getRun());
        $plainTextHandler->setException($this->getException());
        $plainTextHandler->setInspector($this->getInspector());
        $vars["preface"] = "<!--\n\n\n" . $this->templateHelper->escape($plainTextHandler->generateResponse()) . "\n\n\n\n\n\n\n\n\n\n\n-->";

        // ✅ Pass data to template and render it
        $this->templateHelper->setVariables($vars);
        $this->templateHelper->render($templateFile);

        // ✅ Stop further handling; output has been rendered
        return Handler::QUIT;
    }
}
