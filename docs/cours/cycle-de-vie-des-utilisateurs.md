# Cycle de vie des utilisateurs dans Conventio

**Support de cours — Symfony 8.1 / PHP 8.4 — niveau 2ᵉ année**

Ce guide décrit, étape par étape, l'implémentation complète du « cycle de vie d'un
utilisateur » telle qu'elle existe dans ce projet `conventio2` :

```
Inscription ──▶ Vérification de l'e-mail ──▶ Connexion ──▶ Session active ──▶ Déconnexion
   (register)      (lien signé reçu par mail)   (login)     (firewall)        (logout)
```

À la fin de ce guide, vous serez capable de reproduire cette chaîne dans vos propres
projets Symfony : créer l'entité `User`, l'inscription avec mot de passe hashé, la
vérification d'e-mail, la connexion par formulaire via un authenticateur personnalisé,
et la protection des pages.

---

## 1. Objectifs pédagogiques

À l'issue de ce chapitre, vous saurez :

1. Modéliser un utilisateur avec Doctrine (`UserInterface`, `PasswordAuthenticatedUserInterface`).
2. Configurer le système de sécurité de Symfony (`security.yaml`) : *hashers*, *provider*,
   *firewall*, *authenticators*, *access_control*.
3. Écrire un **authenticateur personnalisé** basé sur un formulaire de connexion.
4. Inscrire un utilisateur (formulaire + hachage du mot de passe + connexion automatique).
5. Vérifier l'adresse e-mail grâce au bundle `symfonycasts/verify-email-bundle`.
6. Protéger des pages et gérer la déconnexion.

---

## 2. Prérequis

- Un projet Symfony 8.1 neuf (`symfony new` ou squelette équivalent) avec Doctrine ORM,
  Twig, Form, Validator et Security installés.
- Une base de données configurée dans `.env` (`DATABASE_URL`).
- Un transport d'e-mails configuré (`MAILER_DSN` dans `.env`). En développement, on peut
  utiliser `MAILER_DSN=null://null` (les mails sont ignorés) ou un outil local comme Mailpit.
- Conventions du projet : attributs PHP (`#[Route]`, `#[ORM\...]`), autowiring,
  installation des bundles via `composer require` (Flex), templates Twig stylés avec Tailwind.

---

## 3. Étape 1 — L'entité `User`

Fichier : `src/Entity/User.php`

```php
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre e-mail.')]
    #[Assert\Email(message: 'L\'e-mail {{ value }} n\'est pas valide.')]
    private ?string $email = null;

    /** @var list<string> The user roles */
    #[ORM\Column]
    private array $roles = [];

    /** @var string|null The hashed password */
    #[ORM\Column]
    private ?string $password = null;
    // ... getters/setters ...
}
```

### Points clés à retenir

| Élément | Rôle |
|---|---|
| `implements UserInterface` | Contrat exigé par le système de sécurité : `getRoles()`, `getUserIdentifier()`, `eraseCredentials()`. |
| `implements PasswordAuthenticatedUserInterface` | Ajoute `getPassword()` : Symfony sait lire le mot de passe hashé. |
| `#[ORM\Table(name: '`user`')]` | `user` est un mot réservé en SQL : les backticks sont obligatoires. |
| `UNIQ_IDENTIFIER_EMAIL` | Un e-mail = un compte. La contrainte est posée **en base** (pas seulement en PHP). |
| `getUserIdentifier()` retourne l'e-mail | C'est l'identifiant de connexion (le « username »). |
| `getRoles()` ajoute toujours `ROLE_USER` | Tout utilisateur authentifié a au moins `ROLE_USER` ; `setRoles()` permet d'ajouter `ROLE_ADMIN`… |
| `#[Assert\NotBlank]` / `#[Assert\Email]` | Validation directement sur l'entité, réutilisée par le formulaire. |
| `password` stocke **le hash**, jamais le mot de passe en clair | Le hachage est fait par le `UserPasswordHasherInterface` (étape 4). |
| `eraseCredentials()` | Vide les données sensibles temporaires (ici, rien à faire). |

### Le repository : `src/Repository/UserRepository.php`

```php
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        // persiste le nouveau hash
    }
}
```

`PasswordUpgraderInterface` permet à Symfony de **re-hasher automatiquement** les mots de
passe lors de la connexion si l'algorithme de hachage a changé (ex. bcrypt → argon2id).

### Créer la table en base

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

La migration générée (`migrations/Version20261109162104.php`) crée la table `user` avec
l'index unique sur `email` :

```sql
CREATE TABLE `user` (
  id INT AUTO_INCREMENT NOT NULL,
  email VARCHAR(180) NOT NULL,
  roles LONGTEXT NOT NULL,
  password VARCHAR(255) NOT NULL,
  UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email),
  PRIMARY KEY(id)
) ...
```

> `roles` est stocké en `LONGTEXT` : Doctrine sérialise le tableau PHP en JSON.

---

## 4. Étape 2 — L'inscription (`/register`)

### Le formulaire : `src/Form/RegistrationFormType.php`

```php
$builder
    ->add('email', EmailType::class, [...])
    ->add('plainPassword', PasswordType::class, [
        'mapped' => false,   // ← ce champ n'existe PAS dans l'entité User
        'constraints' => [
            new NotBlank(message: 'Veuillez choisir un mot de passe.'),
            new Length(min: 6, max: 4096, minMessage: '... au moins {{ limit }} caractères.'),
        ],
    ]);
// data_class => User::class
```

- `'mapped' => false` : le mot de passe en clair n'est jamais écrit dans l'entité ;
  seul le **hash** le sera.
- `max: 4096` : protection contre les entrées absurdes (évite de hasher des gigaoctets).

### Le contrôleur : `src/Controller/RegistrationController.php`

```php
#[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
public function register(
    Request $request,
    UserPasswordHasherInterface $passwordHasher,
    EntityManagerInterface $entityManager,
    UserAuthenticatorInterface $userAuthenticator,
    FormLoginAuthenticator $formLoginAuthenticator,
): Response {
    $user = new User();
    $form = $this->createForm(RegistrationFormType::class, $user);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        // 1. Hacher le mot de passe
        $user->setPassword(
            $passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData())
        );

        // 2. Persister en base
        $entityManager->persist($user);
        $entityManager->flush();

        // 3. Connecter immédiatement l'utilisateur
        $this->addFlash('success', 'Votre compte a été créé, vous êtes connecté.');
        return $userAuthenticator->authenticateUser($user, $formLoginAuthenticator, $request)
            ?? new RedirectResponse($this->generateUrl('app_home'));
    }

    return $this->render('registration/register.html.twig', [
        'registrationForm' => $form,
    ]);
}
```

**Ordre des opérations à retenir :** `hashPassword` → `persist` → `flush` → connexion
automatique via `UserAuthenticatorInterface::authenticateUser()` (qui réutilise notre
authentificateur de l'étape 5 pour créer la session).

---

## 5. Étape 3 — `security.yaml` expliqué bloc par bloc

Fichier : `config/packages/security.yaml`

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false
        main:
            lazy: true
            provider: app_user_provider
            custom_authenticators:
                - App\Security\FormLoginAuthenticator
            entry_point: App\Security\FormLoginAuthenticator
            logout:
                path: app_logout

    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/register, roles: PUBLIC_ACCESS }

when@test:
    security:
        password_hashers:
            Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
                algorithm: auto
                cost: 4
                time_cost: 3
                memory_cost: 10
```

| Bloc | Explication |
|---|---|
| `password_hashers: 'auto'` | Choisit automatiquement le meilleur algorithme (argon2id si disponible, sinon bcrypt). Ne stockez jamais de mot de passe en clair. |
| `providers.app_user_provider` | Où aller chercher l'utilisateur : l'entité `App\Entity\User`, identifié par sa propriété `email`. C'est le « provider » utilisé par le firewall. |
| `firewalls.dev` | Désactive la sécurité pour le profiler, les assets, etc. (`security: false`). |
| `firewalls.main.lazy` | La session n'est démarrée que si la page en a besoin. |
| `custom_authenticators` | Liste des authenticateurs du firewall. Ici : notre `FormLoginAuthenticator`. |
| `entry_point` | Quand un visiteur anonyme appelle une page protégée, c'est CET authenticateur qui décide de la réponse (ici : redirection vers `/login`). |
| `logout.path: app_logout` | La route `app_logout` déclenche la déconnexion (interceptée par le firewall : le contrôleur ne s'exécute jamais). |
| `access_control` | **Règle du premier match** : la première règle dont le `path` correspond s'applique. `PUBLIC_ACCESS` = accessible sans être connecté. |
| `when@test` | En environnement de test, on réduit le coût du hachage pour accélérer les tests. |

> Piège classique : sans `entry_point`, un utilisateur anonyme sur une page protégée
> obtient une erreur 500 au lieu d'être redirigé vers `/login`.

---

## 6. Étape 4 — L'authenticateur personnalisé

Fichier : `src/Security/FormLoginAuthenticator.php`

```php
class FormLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

    public function authenticate(Request $request): Passport
    {
        $email = (string) $request->request->get('email', '');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),                                        // qui ?
            new PasswordCredentials((string) $request->request->get('password', '')), // avec quel mot de passe ?
            [
                new CsrfTokenBadge('login', (string) $request->request->get('_csrf_token')), // protection CSRF
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Si l'utilisateur avait tenté d'accéder à une page protégée avant de se connecter :
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }
        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
```

### Anatomie d'un authenticateur

Un authenticateur répond à trois questions :

1. **`supports()`** (hérité de `AbstractLoginFormAuthenticator`) : la requête est-elle une
   soumission POST vers l'URL de login (`/login`) ?
2. **`authenticate()`** : construire un **Passport** qui décrit la tentative :
   - `UserBadge($email)` : l'identifiant ; le *provider* chargera l'utilisateur correspondant.
   - `PasswordCredentials($password)` : le mot de passe en clair, comparé au hash par le hasher.
   - `CsrfTokenBadge('login', $token)` : vérifie le jeton CSRF. **L'identifiant `'login'`
     doit correspondre à celui généré dans le template** : `csrf_token('login')`.
   - `LAST_USERNAME` en session : pour réafficher l'e-mail en cas d'erreur.
3. **`onAuthenticationSuccess()` / `onAuthenticationFailure()`** (failure hérité) : où rediriger
   en cas de succès (page demandée initialement, sinon `app_home`) ou d'échec (retour à `/login`
   avec l'erreur en flash).

### Le contrôleur de login : `src/Controller/SecurityController.php`

```php
#[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
public function login(AuthenticationUtils $authenticationUtils): Response
{
    return $this->render('security/login.html.twig', [
        'last_username' => $authenticationUtils->getLastUsername(),
        'error' => $authenticationUtils->getLastAuthenticationError(),
    ]);
}

#[Route('/logout', name: 'app_logout', methods: ['GET'])]
public function logout(): never
{
    // Interceptée par la clé `logout` du firewall : cette méthode ne s'exécute jamais.
    throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
}
```

Le template `templates/security/login.html.twig` contient le formulaire **manuel** (pas de
composant Form) avec le champ caché indispensable :

```twig
<input type="hidden" name="_csrf_token" value="{{ csrf_token('login') }}">
<input type="email" name="email" value="{{ last_username }}" required>
<input type="password" name="password" required>
```

> Les noms de champs `email`, `password`, `_csrf_token` doivent correspondre exactement à
> ceux lus dans `authenticate()`.

---

## 7. Étape 5 — Vérification de l'e-mail (`symfonycasts/verify-email-bundle`)

Objectif : après l'inscription, l'utilisateur reçoit un e-mail contenant un **lien signé** ;
tant qu'il n'a pas cliqué, son compte reste « non vérifié » (`isVerified = false`).

### 7.1 Installation

```bash
composer require symfonycasts/verify-email-bundle
```

(Flex enregistre le bundle et génère la configuration.)

### 7.2 Ajouter le champ `isVerified` à l'entité

```bash
php bin/console make:entity User   # ajouter : isVerified, bool, non nullable, default false
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

```php
#[ORM\Column]
private bool $isVerified = false;

public function isVerified(): bool { return $this->isVerified; }
public function setIsVerified(bool $isVerified): static { $this->isVerified = $isVerified; return $this; }
```

### 7.3 Envoyer l'e-mail de vérification à l'inscription

Dans `RegistrationController::register()`, après le `flush()` :

```php
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

// dans la signature :
VerifyEmailHelperInterface $verifyEmailHelper,

// après persist/flush :
$signatureComponents = $verifyEmailHelper->generateSignature(
    'app_verify_email',              // route de vérification
    (string) $user->getId(),
    $user->getEmail(),
);

// envoyer le mail contenant $signatureComponents->getSignedUrl()
// (via symfony/mailer, avec un template Twig : email/registration.html.twig)
```

Le lien contient un **hash signé** (expires, id utilisateur, e-mail) : impossible à forger.

### 7.4 La route de vérification

```php
#[Route('/verify/email', name: 'app_verify_email')]
public function verifyUserEmail(Request $request, VerifyEmailHelperInterface $verifyEmailHelper, EntityManagerInterface $em): Response
{
    $id = (string) $request->get('id');
    $user = $em->getRepository(User::class)->find($id);
    // ... ou injecter UserRepository / utiliser ParamConverter implicite

    if (!$user) {
        throw $this->createNotFoundException();
    }

    // valide la signature (expiration + hash + e-mail) et lève une exception si invalide
    $verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $user->getEmail());

    $user->setIsVerified(true);
    $em->flush();

    $this->addFlash('success', 'Votre e-mail est vérifié, merci !');
    return $this->redirectToRoute('app_home');
}
```

### 7.5 Restreindre l'accès aux comptes vérifiés

Deux approches possibles :

- **Contrôle d'accès simple** : dans `access_control`, protéger les pages sensibles avec
  `ROLE_USER` (et éventuellement un contrôle `isVerified()` dans le contrôleur ou un
  *subscriber* `AccessDeniedSubscriber` qui redirige les utilisateurs non vérifiés vers
  une page « veuillez vérifier votre e-mail » avec un bouton « renvoyer l'e-mail »).
- **Voter personnalisé** : `#[IsGranted('IS_VERIFIED')]` sur une action, avec un
  `Voter` qui teste `$user->isVerified()`.

Exemple de renvoi de l'e-mail de vérification :

```php
#[Route('/verify/resend', name: 'app_verify_resend')]
public function resendVerifyEmail(VerifyEmailHelperInterface $verifyEmailHelper, #[CurrentUser] ?User $user): Response
{
    if ($user && !$user->isVerified()) {
        $signatureComponents = $verifyEmailHelper->generateSignature('app_verify_email', (string) $user->getId(), $user->getEmail());
        // ... envoyer le mail à nouveau
    }
    return $this->redirectToRoute('app_home');
}
```

---

## 8. Étape 6 — Protéger des pages et se déconnecter

### Protéger une page

```yaml
# config/packages/security.yaml
    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/register, roles: PUBLIC_ACCESS }
        - { path: ^/verify, roles: PUBLIC_ACCESS }
        - { path: ^/admin, roles: ROLE_ADMIN }
        - { path: ^/, roles: ROLE_USER }        # tout le reste nécessite d'être connecté
```

Dans un contrôleur, on peut aussi vérifier dans le code :

```twig
{% if is_granted('ROLE_ADMIN') %} ... {% endif %}
```
```php
$this->denyAccessUnlessGranted('ROLE_ADMIN');
```

### Se déconnecter

Un simple lien suffit — le firewall intercepte la route :

```twig
<a href="{{ path('app_logout') }}">Se déconnecter</a>
```

La session est invalidée côté serveur par la clé `logout` du firewall.

---

## 9. Schéma récapitulatif des responsabilités

```
Requête HTTP
    │
    ▼
┌─────────────┐   access_control   ┌────────────────────────────┐
│  Firewall   │───────────────────▶│  PUBLIC_ACCESS ? → laisser    │
│  (main)     │                    │  passer                       │
└─────────────┘                    │  ROLE_USER sans session →     │
    │ custom_authenticators         │  entry_point → redirect /login│
    ▼                               └────────────────────────────┘
┌──────────────────────────┐
│ FormLoginAuthenticator   │──▶ Passport : UserBadge + PasswordCredentials + CsrfTokenBadge
│  - supports()            │──▶ provider charge l'User par email
│  - authenticate()        │──▶ hasher compare le mot de passe
│  - onSuccess/onFailure   │──▶ session créée (token) / redirect /login
└──────────────────────────┘
        │
        ▼
   Session (cookie PHPSESSID) ──▶ à chaque requête, le token est rechargé
```

| Composant | Fichier | Rôle |
|---|---|---|
| Entité | `src/Entity/User.php` | Modèle + contrat sécurité |
| Repository | `src/Repository/UserRepository.php` | Chargement + rehash des mots de passe |
| Formulaire | `src/Form/RegistrationFormType.php` | Saisie e-mail + mot de passe |
| Inscription | `src/Controller/RegistrationController.php` | Hash + persist + connexion auto + e-mail de vérification |
| Login | `src/Controller/SecurityController.php` | Affichage du formulaire |
| Authenticateur | `src/Security/FormLoginAuthenticator.php` | Vérifie les credentials, crée la session |
| Config | `config/packages/security.yaml` | Hashers, provider, firewall, règles d'accès |
| Templates | `templates/security/login.html.twig`, `templates/registration/register.html.twig` | Formulaires (avec jeton CSRF) |

---

## 10. Commandes utiles

```bash
composer require symfonycasts/verify-email-bundle   # étape 5
php bin/console make:entity User                     # créer/étendre l'entité
php bin/console make:authenticator                   # générer un authenticateur (form login)
php bin/console make:registration-form               # générer formulaire + contrôleur d'inscription
php bin/console make:migration && php bin/console doctrine:migrations:migrate
php bin/console debug:router                         # liste des routes (app_login, app_logout, app_register...)
php bin/console debug:firewall                       # détail du firewall et de ses authenticateurs
php bin/console debug:config security                # configuration de sécurité résolue
```

---

## 11. Pièges fréquents (check-list de relecture)

- [ ] Mot de passe **hashé** (`$passwordHasher->hashPassword(...)`) avant `persist`, jamais en clair.
- [ ] `persist()` **et** `flush()` — oublier `flush()` = rien en base.
- [ ] Champ `plainPassword` en `'mapped' => false` : jamais de propriété `plainPassword` dans l'entité.
- [ ] Jeton CSRF : `csrf_token('login')` dans le template = `CsrfTokenBadge('login', ...)` dans l'authenticateur.
- [ ] Noms de champs du formulaire = ceux lus dans `authenticate()` (`email`, `password`, `_csrf_token`).
- [ ] `entry_point` défini sur le firewall, sinon erreur 500 pour les anonymes.
- [ ] `access_control` : **première règle gagnante** — ordonner du plus spécifique au plus général.
- [ ] Table `user` entre backticks (mot réservé SQL).
- [ ] `getRoles()` renvoie toujours au moins `ROLE_USER`.
- [ ] `when@test` : coût de hachage réduit pour des tests rapides.
- [ ] Le contrôleur `app_logout` ne s'exécute jamais : c'est le firewall qui gère la déconnexion.

---

## 12. Exercices proposés

1. **Reproduire le cycle complet** dans un projet neuf, étape par étape, sans copier-coller
   ce dépôt : entité → migration → inscription → login → logout.
2. **Ajouter la vérification d'e-mail** (étape 5) : champ `isVerified`, envoi du lien signé,
   route `app_verify_email`, renvoi du mail.
3. **Protéger une page** `/profil` avec `ROLE_USER` et afficher l'e-mail de l'utilisateur
   connecté (`app.user.email` dans Twig).
4. **Ajouter un rôle admin** : créer un utilisateur `ROLE_ADMIN` en console
   (`php bin/console security:hash-password` pour le hash) et protéger `/admin`.
5. **Écrire un test fonctionnel** (`WebTestCase`) : soumission du formulaire de login avec
   `$client->loginUser($user)` et assertion de la redirection.
6. **Aller plus loin** : mot de passe oublié avec `symfonycasts/reset-password-bundle`,
   double authentification (2FA) avec `scheb/2fa`.

---

## 13. Références officielles

- [Sécurité Symfony — la documentation](https://symfony.com/doc/current/security.html)
- [Les authenticateurs (custom authenticator)](https://symfony.com/doc/current/security/custom_authenticator.html)
- [Form Login Authenticator](https://symfony.com/doc/current/security/form_login_setup.html)
- [symfonycasts/verify-email-bundle](https://github.com/symfonycasts/verify-email-bundle)
- [Validation (contraintes `Assert`)](https://symfony.com/doc/current/validation.html)
- [Doctrine — cycle de vie des entités (persist/flush)](https://symfony.com/doc/current/doctrine.html)
