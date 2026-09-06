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
$entity = $em = null;
$original = null;
$created = false;
try {
    $k->boot();
    $c = $k->getContainer();
    $em = $c->get('doctrine.orm.entity_manager');
    $integration = $c->get('mautic.integration.mailganer');
    $entity = $em->getRepository(Mautic\PluginBundle\Entity\Integration::class)->findOneBy(['name' => 'Mailganer']);
    if (!$entity) {
        $created = true;
        $entity = new Mautic\PluginBundle\Entity\Integration();
        $entity->setName('Mailganer');
        $entity->setPlugin($em->getRepository(Mautic\PluginBundle\Entity\Plugin::class)->findOneBy(['bundle' => 'MailganerBundle']));
    }
    $original = [$entity->getApiKeys(), $entity->getIsPublished()];
    $entity->setIsPublished(true);
    $em->persist($entity);
    $em->flush();
    $control = $c->get(MauticPlugin\MailganerBundle\Mailer\SendingControl::class);
    foreach ([['rate' => 40, 'concurrency' => 3], ['rate' => null, 'concurrency' => 4], ['rate' => 0, 'concurrency' => 1]] as $expected) {
        $control->save($expected);
        $actual = $control->current();
        if ($actual !== $expected) {
            throw new RuntimeException('Saved values differ: '.json_encode($actual));
        }echo json_encode($actual).PHP_EOL;
    }
    $requests = 0;
    $client = new Symfony\Component\HttpClient\MockHttpClient(static function () use (&$requests) {
        ++$requests;
        throw new RuntimeException('Disabled transport attempted an API call');
    });
    $transport = new MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport(
        'fixture', client: $client, journalDirectory: getenv('MG_CACHE').'/receipts',
        limitsProvider: $control->current(...), usePackages: false
    );
    $entity->setIsPublished(false);
    $em->persist($entity);
    $em->flush();
    try {
        $control->current();
        throw new LogicException('Disabled gate failed');
    } catch (Symfony\Component\Mailer\Exception\TransportException $e) {
    }
    try {
        $transport->send((new Symfony\Component\Mime\Email())->from('sender@example.invalid')->to('recipient@example.invalid')->subject('Disabled fixture')->text('No API request allowed'));
        throw new LogicException('Already-created transport ignored disabled state');
    } catch (Symfony\Component\Mailer\Exception\TransportException $e) {
        if (!str_contains($e->getMessage(), 'disabled')) {
            throw $e;
        }
    }
    if ($requests !== 0) {
        throw new LogicException('Disabled transport made an API request');
    }
    echo 'PASS controls live database Mautic '.$k->getVersion().PHP_EOL;
} finally {
    if ($created) {
        $em->remove($entity);
        $em->flush();
    } elseif ($original) {
        $entity->setApiKeys($original[0]);
        $entity->setIsPublished($original[1]);
        $em->persist($entity);
        $em->flush();
    }$k->shutdown();
    (new Symfony\Component\Filesystem\Filesystem())->remove(getenv('MG_CACHE'));
}
