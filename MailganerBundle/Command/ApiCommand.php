<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MailganerBundle\Api\MailganerApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Typed, documented operator actions: no unrestricted URL or credentials on the command line. */
final class ApiCommand extends Command
{
    public const ACTIONS = [
        'account' => ['GET', '/api/v2/authkey', []],
        'domains' => ['GET', '/api/v2/blist/domains', []],
        'domain-verify' => ['POST', '/api/v2/blist/domains/verify', ['domain']],
        'delivery' => ['GET', '/api/v2/issue/status', ['x_track_id']],
        'history' => ['GET', '/api/v2/issue/ext_status', ['x_track_id']],
        'package-status' => ['GET', '/api/v2/package/status', ['external_id']],
        'package-statistics' => ['GET', '/api/v1/get_issue_stat', ['id']],
        'package-failures' => ['GET', '/api/v2/issue/report/non-delivery', ['issuen']],
        'package-stop' => ['GET', '/api/v1/package_stop', ['pack_id']],
        'statistics' => ['GET', '/api/v2/issue/statistics', ['date_from', 'date_to']],
        'non-delivery' => ['GET', '/api/v2/blist/report/non-delivery', ['date_from', 'date_to']],
        'complaints' => ['GET', '/api/v2/blist/report/fbl', ['date_from', 'date_to']],
        'unsubscribes' => ['GET', '/api/v2/blist/report/unsubscribe', ['date_from', 'date_to']],
        'stop-list-search' => ['GET', '/api/v2/stop-list/search', ['email']],
        'stop-list-add' => ['POST', '/api/v2/stop-list/add', ['mail_from', 'email']],
        'stop-list-remove' => ['POST', '/api/v2/stop-list/remove', ['mail_from', 'email']],
        'stop-list-failed' => ['GET', '/api/v2/stop-list/failed', []],
        'stop-list-fbl' => ['GET', '/api/v2/stop-list/fbl', []],
        'stop-list-unsubscribe' => ['GET', '/api/v2/stop-list/unsubscribe', []],
        'mailing' => ['GET', '/api/v2/blist', ['id']],
        'webhook-configure' => ['POST', '/api/v2/blist/update', ['id', 'webhook_url']],
    ];

    public function __construct(private CoreParametersHelper $parameters, private IntegrationHelper $integrations, private HttpClientInterface $client, private TranslatorInterface $translator)
    {
        parent::__construct('mautic:mailganer:api');
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('description', [], 'mailganer_api'));
        $this->addArgument('action', InputArgument::REQUIRED, implode(', ', array_keys(self::ACTIONS)));
        $this->addOption('data', null, InputOption::VALUE_REQUIRED, $this->translator->trans('data', [], 'mailganer_api'), '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $integration = $this->integrations->getIntegrationObject('Mailganer');
        if (!$integration || !$integration->getIntegrationSettings()?->getIsPublished()) {
            throw new \RuntimeException($this->translator->trans('enable', [], 'mailganer_api'));
        }
        $action = (string) $input->getArgument('action');
        if (!isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException($this->translator->trans('unknown', [], 'mailganer_api'));
        }
        $dsn = Dsn::fromString((string) $this->parameters->get('mailer_dsn'));
        if (!in_array($dsn->getScheme(), ['mailganer', 'mailganer+api'], true)) {
            throw new \RuntimeException($this->translator->trans('dsn', [], 'mailganer_api'));
        }
        $key = $dsn->getOption('key') ?? $dsn->getPassword() ?? $dsn->getUser();
        if (!is_string($key) || '' === trim($key)) {
            throw new \RuntimeException($this->translator->trans('key', [], 'mailganer_api'));
        }
        $data = json_decode((string) $input->getOption('data'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException($this->translator->trans('object', [], 'mailganer_api'));
        }
        [$method, $path, $required] = self::ACTIONS[$action];
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field]) || '' === (string) $data[$field]) {
                throw new \InvalidArgumentException($this->translator->trans('missing', ['%field%' => $field], 'mailganer_api'));
            }
        }
        if ('webhook-configure' === $action) {
            if ('https' !== parse_url((string) $data['webhook_url'], PHP_URL_SCHEME)) {
                throw new \InvalidArgumentException($this->translator->trans('https', [], 'mailganer_api'));
            }
            $data = ['id' => $data['id'], 'webhook_url' => $data['webhook_url'], 'webhook_active' => true];
        }
        $api = new MailganerApi($this->client, trim($key), 'default' === $dsn->getHost() ? 'api.samotpravil.ru' : $dsn->getHost());
        $result = $api->request($method, $path, $data);
        $output->writeln(json_encode(self::redact($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    public static function redact(array $result): array
    {
        foreach ($result as $key => &$value) {
            if (preg_match('/^(api_key|secret|password|token|access_token|refresh_token)$/i', (string) $key)) {
                $value = '[redacted]';
            } elseif (is_array($value)) {
                $value = self::redact($value);
            }
        }

        return $result;
    }
}
