<?php

namespace App\Core\Exceptions;

use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\Handler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Exception\Formatter;

/** Adapts the legacy Whoops page without attaching request or session payloads. */
class CustomPrettyPageHandler extends PrettyPageHandler
{
    /** Render the historical layout without Whoops' unbounded request tables. */
    public function handle()
    {
        // If the handler shouldn't run under current context (e.g., not web), exit
        if (!$this->handleUnconditionally()) {
            if (PHP_SAPI === 'cli') {
                return Handler::DONE;
            }
        }

        // Keep the established custom Whoops layout.
        $templateFile = $this->getResource("views/layout.html.php");

        // Collect assets and exception details expected by that layout.
        $vars = [
            "page_title" => $this->getPageTitle(),
            "stylesheet" => file_get_contents($this->getResource("css/whoops.base.css")),
            "zepto"      => file_get_contents($this->getResource("js/zepto.min.js")),
            "prismJs"    => file_get_contents($this->getResource("js/prism.js")),
            "prismCss"   => file_get_contents($this->getResource("css/prism.css")),
            "clipboard"  => file_get_contents($this->getResource("js/clipboard.min.js")),
            "javascript" => file_get_contents($this->getResource("js/whoops.base.js")),

            // Render the same Whoops view fragments as the legacy layout.
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

            // Exception metadata is supplied by Whoops' inspector.
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

            // Prefer application frames when available.
            "active_frames_tab" => count($this->getExceptionFrames()) && $this->getExceptionFrames()->offsetGet(0)->isApplication() ? 'application' : 'all',
            "has_frames_tabs" => $this->getApplicationPaths(),

            // Whoops masks only configured top-level keys. Omit payload tables
            // entirely so nested credentials and arbitrary cookie names stay out.
            "tables" => [],
        ];

        // Retain the historical plain-text exception preface.
        $plainTextHandler = new PlainTextHandler();
        $plainTextHandler->setRun($this->getRun());
        $plainTextHandler->setException($this->getException());
        $plainTextHandler->setInspector($this->getInspector());
        $vars["preface"] = "<!--\n\n\n" . $this->templateHelper->escape($plainTextHandler->generateResponse()) . "\n\n\n\n\n\n\n\n\n\n\n-->";

        // Render through the established Whoops template helper.
        $this->templateHelper->setVariables($vars);
        $this->templateHelper->render($templateFile);

        return Handler::QUIT;
    }
}
