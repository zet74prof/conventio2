<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

trait DatabaseSchemaTrait
{
    private function resetDatabaseSchema(): EntityManagerInterface
    {
        $container = self::getContainer();
        $dbPath = $container->getParameter('kernel.project_dir').'/var/data_test.db';

        $entityManager = $container->get('doctrine')->getManager();
        $entityManager->getConnection()->close();

        if (is_file($dbPath)) {
            unlink($dbPath);
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
