<?php
// app/core/mail.php
namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mail
{
    protected $mail;                   // PHPMailer instance
    protected $sections = [];          // Stores view sections for layout injection
    protected $currentSection = null;  // Tracks the current section being rendered
    protected $layout = null;          // Optional layout file
    protected $customViewPath = null;  // Optional custom view path provided by developer

    public function __construct()
    {
        // Initialize PHPMailer
        $this->mail = new PHPMailer(true);
        $config = require __DIR__ . '/../../config/mail.php';

        // Set mail server settings
        $this->mail->SMTPDebug = 0;
        $this->mail->isSMTP();
        $this->mail->Host = $config['host'];
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $config['username'];
        $this->mail->Password = $config['password'];
        $this->mail->SMTPSecure = $config['encryption'];
        $this->mail->Port = $config['port'];
        $this->mail->setFrom($config['from_address'], $config['from_name']);
        $this->mail->isHTML(true); // Enable HTML emails
    }

    // Set the recipient
    public function to($address, $name = '')
    {
        $this->mail->addAddress($address, $name);
        return $this;
    }

    // Set the email subject
    public function subject($subject)
    {
        $this->mail->Subject = $subject;
        return $this;
    }

    // Set the HTML body directly
    public function body($body)
    {
        $this->mail->Body = $body;
        return $this;
    }

    // Add a reply-to address
    public function replyTo($address, $name = '')
    {
        $this->mail->addReplyTo($address, $name);
        return $this;
    }

    // Set a layout file (e.g., for headers/footers)
    public function layout(string $layout)
    {
        $this->layout = $layout;
        return $this;
    }

    // Set a custom path for view templates
    public function viewPath(string $path)
    {
        $this->customViewPath = rtrim($path, '/');
        return $this;
    }

    // Load and parse an email template view with data
    public function template(string $view, array $data = [])
    {
        $defaultPath = __DIR__ . "/../../views/emails/{$view}.squehub.php";
        $viewPath = $defaultPath;

        // Use custom path if default doesn't exist
        if (!file_exists($defaultPath)) {
            if ($this->customViewPath) {
                $customPath = $this->customViewPath . "/{$view}.squehub.php";
                if (file_exists($customPath)) {
                    $viewPath = $customPath;
                } else {
                    throw new \Exception("❌ Email template '{$view}' not found in custom path '{$this->customViewPath}'.");
                }
            } else {
                throw new \Exception("❌ Email template '{$view}' not found.");
            }
        }

        // Load and compile view template
        $content = file_get_contents($viewPath);
        $parsed = $this->processBladeSyntax($content, $data);

        // Inject layout if defined
        if ($this->layout) {
            $layoutPath = __DIR__ . "/../../views/emails/{$this->layout}.squehub.php";
            if (!file_exists($layoutPath)) {
                throw new \Exception("❌ Layout '{$this->layout}' not found.");
            }

            $layoutContent = file_get_contents($layoutPath);
            $parsed = $this->injectSections($layoutContent);
        }

        $this->mail->Body = $parsed;
        return $this;
    }

    // Send the email
    public function send()
    {
        try {
            return $this->mail->send();
        } catch (Exception $e) {
            throw new \Exception("❌ Email could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
        }
    }

    // Compile blade-like syntax into PHP and render output
    protected function processBladeSyntax($content, $data)
    {
        extract($data); // Make variables available in template

        // Blade-like: @extends
        $content = preg_replace_callback('/@extends\(\'([a-zA-Z0-9._-]+)\'\)/', fn($m) => "<?php \\App\\Core\\View::extends('{$m[1]}'); ?>", $content);

        // Blade-like: @include
        $content = preg_replace_callback('/@include\(\'([a-zA-Z0-9._-]+)\'\)/', fn($m) => "<?php \\App\\Core\\View::include('{$m[1]}'); ?>", $content);

        // Blade-like: @section
        $content = preg_replace_callback('/@section\(\'([a-zA-Z0-9_-]+)\'\)/', fn($m) => "<?php \\App\\Core\\View::startSection('{$m[1]}'); ?>", $content);

        // Blade-like: @endsection
        $content = str_replace('@endsection', '<?php \\App\\Core\\View::endSection(); ?>', $content);

        // Blade-like: @yield with optional default
        $content = preg_replace_callback('/@yield\(\'([a-zA-Z0-9_-]+)\'(?:,\s*\'([^\']*)\')?\)/', function ($m) {
            $default = isset($m[2]) ? addslashes($m[2]) : '';
            return "<?php \\App\\Core\\View::yieldSection('{$m[1]}', '$default'); ?>";
        }, $content);

        // Blade-like: conditionals and loops
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

        // Blade-like: echoing variables
        $content = preg_replace_callback('/@([a-zA-Z_][a-zA-Z0-9_]*)/', function ($m) use ($data) {
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

        // Evaluate parsed template and return output
        ob_start();
        eval('?>' . $content);
        return ob_get_clean();
    }

    // Parse layout with injected section content
    protected function injectSections($layoutContent)
    {
        return $this->processBladeSyntax($layoutContent, []);
    }
}
