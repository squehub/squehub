<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\Mail;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class MailTemplateCaseTest extends TestCase
{
    public function testCustomTemplatePathAcceptsFirstLetterVariantsInEverySegment(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Emails/Notice/Weekly.squehub.php', 'Weekly message');
            $mail = (new Mail())->viewPath($project->path('Emails'))->template('notice/weekly');

            self::assertSame('Weekly message', $this->body($mail));
        } finally {
            $project->remove();
        }
    }

    public function testTemplateDataCannotReplaceExecutableTemplateSource(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Emails/Notice/Weekly.squehub.php', 'Trusted: @content');
            $untrusted = '<?php echo "EXECUTED"; ?>';

            $mail = (new Mail())->viewPath($project->path('Emails'))
                ->template('notice/weekly', ['content' => $untrusted]);

            self::assertSame('Trusted: ' . htmlspecialchars($untrusted), $this->body($mail));
        } finally {
            $project->remove();
        }
    }

    public function testDefaultTemplateAndLayoutAcceptFirstLetterVariants(): void
    {
        $emails = dirname(__DIR__, 2) . '/Project/Views/Emails';
        $directory = $emails . '/MailCase' . bin2hex(random_bytes(6));
        $notice = $directory . '/Notice';
        $layouts = $directory . '/Layouts';
        $createdEmails = !is_dir($emails);
        mkdir($notice, 0777, true);
        mkdir($layouts, 0777, true);
        $template = $notice . '/Weekly.squehub.php';
        $layout = $layouts . '/Standard.squehub.php';

        try {
            file_put_contents($template, 'Template body');
            file_put_contents($layout, 'Layout body');
            $name = basename($directory);
            $mail = (new Mail())->template($name . '/notice/weekly');
            self::assertSame('Template body', $this->body($mail));

            $mail->layout($name . '/layouts/standard')->template($name . '/notice/weekly');
            self::assertSame('Layout body', $this->body($mail));
        } finally {
            foreach ([$template, $layout] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($notice);
            rmdir($layouts);
            rmdir($directory);
            if ($createdEmails) {
                rmdir($emails);
            }
        }
    }

    private function body(Mail $mail): string
    {
        $mailer = (new ReflectionProperty(Mail::class, 'mail'))->getValue($mail);
        self::assertInstanceOf(PHPMailer::class, $mailer);

        return $mailer->Body;
    }
}
