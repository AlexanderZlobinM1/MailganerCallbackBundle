<?php

$root = $argv[1];
$web = is_dir($root.'/docroot') ? $root.'/docroot' : $root;
chdir($root);
define('IN_MAUTIC_CONSOLE', 1);
define('MAUTIC_ROOT_DIR', $web);
require $web.'/app/config/bootstrap.php';
putenv('MG_ROOT='.$root);
putenv('MG_CACHE=/tmp/mg-controls-'.bin2hex(random_bytes(8)));
class ControlsKernel extends AppKernel
{
    public function getProjectDir(): string
    {
        return getenv('MG_ROOT');
    }

    public function getCacheDir(): string
    {
        return getenv('MG_CACHE');
    }
}
$k = new ControlsKernel('prod', false);
try {
    $k->boot();
    $c = $k->getContainer();
    $em = $c->get('doctrine.orm.entity_manager');
    $em->beginTransaction();
    $integration = $c->get('mautic.integration.mailganer');
    $repo = $em->getRepository(Mautic\PluginBundle\Entity\Integration::class);
    foreach ($repo->findBy(['name' => ['Mailganer', 'MailganerCallback']]) as $old) {
        $em->remove($old);
    }
    $em->flush();
    $oldPlugin = $em->getRepository(Mautic\PluginBundle\Entity\Plugin::class)->findOneBy(['bundle' => '__BUNDLE__']);
    if ($oldPlugin) {
        $em->remove($oldPlugin);
        $em->flush();
    }
    $make = function (string $name, array $keys, bool $enabled) use ($em, $integration) {
        $e = new Mautic\PluginBundle\Entity\Integration();
        $e->setName($name);
        $e->setSupportedFeatures([]);
        $e->setIsPublished($enabled);
        $integration->encryptAndSetApiKeys($keys, $e);
        $em->persist($e);

        return $e;
    };
    $legacy = $make('MailganerCallback', ['mailganer_callback_handle_failed' => true, 'mailganer_callback_log_payload' => true], true);
    $canonical = $make('Mailganer', ['mailganer_handle_failed' => false, 'mailganer_send_rate' => 42, 'mailganer_concurrency' => 3], false);
    $em->flush();
    $plugin = new Mautic\PluginBundle\Entity\Plugin();
    $plugin->setBundle('__BUNDLE__');
    $plugin->setName('Mailganer isolated registration');
    $plugin->setVersion('test');
    $subscriber = $c->get(MauticPlugin\__BUNDLE__\EventSubscriber\SettingsLifecycleSubscriber::class);
    $subscriber->onInstall(new Mautic\PluginBundle\Event\PluginInstallEvent($plugin, null, null));
    $em->persist($plugin);
    $em->flush();
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    $assert(count($repo->findBy(['name' => ['Mailganer', 'MailganerCallback']])) === 1, 'Legacy row was not consolidated');
    $assert(!$canonical->getIsPublished(), 'Explicit disabled state changed');
    $assert($canonical->getPlugin() === $plugin, 'Fresh installation did not adopt retained settings');
    $keys = $integration->decryptApiKeys($canonical->getApiKeys());
    $assert(false === (bool) $keys['mailganer_handle_failed'], 'False setting was overridden');
    $assert(true === (bool) $keys['mailganer_log_payload'], 'Legacy-only value was lost');
    $assert(42 === (int) $keys['mailganer_send_rate'], 'API setting was lost');
    $integration->encryptAndSetApiKeys(['mailganer_handle_failed' => true], $canonical);
    $em->persist($canonical);
    $em->flush();
    $keys = $integration->decryptApiKeys($canonical->getApiKeys());
    $assert(42 === (int) $keys['mailganer_send_rate'] && 3 === (int) $keys['mailganer_concurrency'], 'Callback save dropped API fields');
    $before = $canonical->getApiKeys();
    MauticPlugin\__BUNDLE__\Model\SharedSettings::migrate($em, $plugin);
    $em->flush();
    $assert($before === $canonical->getApiKeys(), 'Migration is not idempotent');
    echo 'PASS shared settings migration, native fresh registration, disabled state, callback save retains API fields Mautic '.$k->getVersion().PHP_EOL;
} finally {
    if (isset($em)) {
        $em->rollback();
        $em->clear();
    }
    $k->shutdown();
    (new Symfony\Component\Filesystem\Filesystem())->remove(getenv('MG_CACHE'));
}
