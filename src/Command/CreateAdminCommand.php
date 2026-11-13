<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Crée un compte administrateur (ou promeut un compte existant en ROLE_ADMIN).',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail du compte administrateur')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe en clair (sera haché)')
            ->setHelp(<<<'HELP'
                Exemple : <info>php bin/console app:create-admin admin@ac-grenoble.fr motdepasse</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));
        $password = (string) $input->getArgument('password');

        $user = $this->userRepository->findOneBy(['email' => $email]) ?? new User();
        $created = null === $user->getId();

        if ($created) {
            $user->setEmail($email);
            $user->setNom('Admin');
            $user->setPrenom('Admin');
            $user->setProfile(User::PROFILE_TEACHER);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setIsVerified(true);

        $roles = $user->getRoles();
        if (!\in_array('ROLE_ADMIN', $roles, true)) {
            $user->setRoles([...$roles, 'ROLE_ADMIN']);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Le compte %s %s maintenant les droits ROLE_ADMIN.', $email, $created ? 'a été créé et dispose' : 'dispose'));

        return Command::SUCCESS;
    }
}
