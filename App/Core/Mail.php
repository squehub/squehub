<?php
namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use App\Foundation\Environment;
use App\Mail\MailConfigurationException;
use App\Mail\MailException;

/**
 * Legacy SMTP mail builder backed by PHPMailer and project email views.
 * Its template path precedence remains separate from the v2 View subsystem.
 */
class Mail
{
    protected $mail;                   // PHPMailer instance
    protected $sections = [];          // Stores view sections for layout injection
    protected $currentSection = null;  // Tracks the current section being rendered
    protected $layout = null;          // Optional layout file
    protected $customViewPath = null;  // Optional custom view path provided by developer

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        // The legacy fluent/template API remains independent of the v2 draft
        // object, but both paths read one environment-backed configuration source.
        $environment = new Environment(dirname(__DIR__, 2));
        $config = require __DIR__ . '/../../Config/Mail.php';
        $smtp = $config['transports']['smtp'];

        $this->mail->SMTPDebug = 0;
        $this->mail->isSMTP();
        $this->mail->Host = (string) ($smtp['host'] ?? '');
        $this->mail->SMTPAuth = ($smtp['username'] ?? null) !== null;
        $this->mail->Username = (string) ($smtp['username'] ?? '');
        $this->mail->Password = (string) ($smtp['password'] ?? '');
        $this->mail->SMTPSecure = ($smtp['encryption'] ?? 'tls') === 'none' ? '' : (string) $smtp['encryption'];
        $this->mail->Port = (int) ($smtp['port'] ?? 587);
        $this->mail->Timeout = (int) ($smtp['timeout'] ?? 10);
        $this->mail->SMTPAutoTLS = ($smtp['encryption'] ?? 'tls') === 'tls';
        $this->mail->SMTPOptions = ['ssl' => [
            'verify_peer' => $smtp['verify_peer'] ?? true,
            'verify_peer_name' => $smtp['verify_peer'] ?? true,
            'allow_self_signed' => $smtp['allow_self_signed'] ?? false,
        ]];
        if (($config['from']['address'] ?? null) !== null) {
            try {
                $this->mail->setFrom($config['from']['address'], $config['from']['name'] ?? '');
            } catch (\Throwable) {
                throw new MailConfigurationException('Legacy mail sender is invalid.');
            }
        }
        $this->mail->isHTML(true); // Enable HTML emails
    }

    public function to($address, $name = '')
    {
        $this->mail->addAddress($address, $name);
        return $this;
    }

    public function subject($subject)
    {
        $this->mail->Subject = $subject;
        return $this;
    }

    public function body($body)
    {
        $this->mail->Body = $body;
        return $this;
    }

    public function replyTo($address, $name = '')
    {
        $this->mail->addReplyTo($address, $name);
        return $this;
    }

    public function layout(string $layout)
    {
        $this->layout = $layout;
        return $this;
    }

    public function viewPath(string $path)
    {
        $this->customViewPath = rtrim($path, '/');
        return $this;
    }

    public function template(string $view, array $data = [])
    {
        $defaultDirectory = __DIR__ . '/../../Project/Views/Emails';
        $viewPath = $this->resolveEmailTemplate($defaultDirectory, $view);

        // Project email views take precedence over the optional custom path.
        if ($viewPath === null) {
            if ($this->customViewPath) {
                $viewPath = $this->resolveEmailTemplate($this->customViewPath, $view);
                if ($viewPath === null) {
                    throw new \Exception("❌ Email template '{$view}' not found in custom path '{$this->customViewPath}'.");
                }
            } else {
                throw new \Exception("❌ Email template '{$view}' not found.");
            }
        }

        $content = file_get_contents($viewPath);
        $parsed = $this->processBladeSyntax($content, $data);

        if ($this->layout) {
            $layoutPath = $this->resolveEmailTemplate($defaultDirectory, $this->layout);
            if ($layoutPath === null) {
                throw new \Exception("❌ Layout '{$this->layout}' not found.");
            }

            $layoutContent = file_get_contents($layoutPath);
            $parsed = $this->injectSections($layoutContent);
        }

        $this->mail->Body = $parsed;
        return $this;
    }

    /** Resolve first-letter path variants without leaving the selected email root. */
    private function resolveEmailTemplate(string $directory, string $name): ?string
    {
        $root = realpath($directory);
        if ($root === false) {
            return null;
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            return null;
        }

        $path = $root;
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $found = null;
            foreach (array_unique([$part, ucfirst($part), lcfirst($part)]) as $variant) {
                $candidate = $path . DIRECTORY_SEPARATOR . $variant . ($index === $last ? '.squehub.php' : '');
                if ($index === $last ? is_file($candidate) : is_dir($candidate)) {
                    $found = $candidate;
                    break;
                }
            }

            if ($found === null) {
                return null;
            }
            $path = $found;
        }

        $resolved = realpath($path);
        return $resolved !== false && str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ? $resolved
            : null;
    }

    public function send()
    {
        if ($this->mail->Host === '' || $this->mail->From === '') {
            throw new MailConfigurationException('Legacy mail needs a configured SMTP host and sender.');
        }
        try {
            return $this->mail->send();
        } catch (\Throwable) {
            // PHPMailer error details may contain addresses or message data.
            throw new MailException('Legacy mail send failed.');
        }
    }

    /** Render the legacy email directive syntax with the provided variables. */
    protected function processBladeSyntax($content, $data)
    {
        $content = preg_replace_callback('/@extends\(\'([a-zA-Z0-9._-]+)\'\)/', fn($m) => "<?php \\App\\Core\\View::extends('{$m[1]}'); ?>", $content);

        $content = preg_replace_callback('/@include\(\'([a-zA-Z0-9._-]+)\'\)/', fn($m) => "<?php \\App\\Core\\View::include('{$m[1]}'); ?>", $content);

        $content = preg_replace_callback('/@section\(\'([a-zA-Z0-9_-]+)\'\)/', fn($m) => "<?php \\App\\Core\\View::startSection('{$m[1]}'); ?>", $content);

        $content = str_replace('@endsection', '<?php \\App\\Core\\View::endSection(); ?>', $content);

        $content = preg_replace_callback('/@yield\(\'([a-zA-Z0-9_-]+)\'(?:,\s*\'([^\']*)\')?\)/', function ($m) {
            $default = isset($m[2]) ? addslashes($m[2]) : '';
            return "<?php \\App\\Core\\View::yieldSection('{$m[1]}', '$default'); ?>";
        }, $content);

        $content = preg_replace('/@if\s*\((.*?)\)/', '<?php if ($1): ?>', $content);
        $content = preg_replace('/@elseif\s*\((.*?)\)/', '<?php elseif ($1): ?>', $content);
        $content = str_replace('@else', '<?php else: ?>', $content);
        $content = str_replace('@endif', '<?php endif; ?>', $content);
        $content = preg_replace('/@foreach\s*\((.*?)\)/', '<?php foreach ($1): ?>', $content);
        $content = str_replace('@endforeach', '<?php endforeach; ?>', $content);
        $content = preg_replace('/@for\s*\((.*?)\)/', '<?php for ($1): ?>', $content);
        $content = str_replace('@endfor', '<?php endfor; ?>', $content);
        $content = preg_replace('/@while\s*\((.*?)\)/', '<?php while ($1): ?>', $content);
        $content = str_replace('@endwhile', '<?php endwhile; ?>', $content);

        $content = preg_replace_callback('/@([a-zA-Z_][a-zA-Z0-9_]*)/', function ($m) {
            $var = $m[1];
            $directives = [
                'if', 'elseif', 'else', 'endif',
                'foreach', 'endforeach', 'for', 'endfor',
                'while', 'endwhile', 'section', 'endsection',
                'yield', 'extends', 'include'
            ];
            if (in_array($var, $directives)) return '@' . $var;
            return '<?= isset($' . $var . ') ? htmlspecialchars($' . $var . ') : "" ?>';
        }, $content);

        return $this->renderCompiledTemplate($content, $data);
    }

    /** Keep template variables separate from the compiled source passed to eval. */
    private function renderCompiledTemplate(string $__squehub_mail_source, array $__squehub_mail_data): string
    {
        $__squehub_mail_bufferLevel = ob_get_level();
        ob_start();
        try {
            extract($__squehub_mail_data, EXTR_SKIP);
            eval('?>' . $__squehub_mail_source);
            return (string) ob_get_clean();
        } catch (\Throwable $__squehub_mail_error) {
            while (ob_get_level() > $__squehub_mail_bufferLevel) {
                ob_end_clean();
            }
            throw $__squehub_mail_error;
        }
    }

    protected function injectSections($layoutContent)
    {
        return $this->processBladeSyntax($layoutContent, []);
    }
}
