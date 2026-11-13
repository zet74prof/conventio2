<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nom/prenom/profile to user and create the app_setting table (admin-configurable e-mail domains)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD nom VARCHAR(100) NOT NULL, ADD prenom VARCHAR(100) NOT NULL, ADD profile VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE TABLE app_setting (id INT AUTO_INCREMENT NOT NULL, setting_key VARCHAR(100) NOT NULL, value VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_APP_SETTING_KEY (setting_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO app_setting (setting_key, value) VALUES (\'email_domain_student\', \'lycee-faure.fr\'), (\'email_domain_teacher\', \'ac-grenoble.fr\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP nom, DROP prenom, DROP profile');
        $this->addSql('DROP TABLE app_setting');
    }
}
