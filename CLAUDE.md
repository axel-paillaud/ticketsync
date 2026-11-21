# TicketSync - Résumé du Projet

## Description
Système de gestion de tickets pour gérer les demandes clients. Projet personnel d'apprentissage Symfony utilisé en production.

## Stack Technique
- **Framework**: Symfony 7.3
- **Base de données**: SQLite (dev)
- **Frontend**: Twig + Bootstrap 5 + Bootstrap Icons + AssetMapper
- **PHP**: 8.2+

## Fonctionnalités Actuelles

### Authentification
- Système de connexion/inscription
- Gestion des utilisateurs avec rôles (USER, ADMIN)

### Multi-tenant & Routing
- URLs organisées par organization: `/{organizationSlug}/tickets/...`
- Route admin: `/admin` (tous les tickets, toutes organizations)
- Route home: `/` (redirige vers l'organization de l'utilisateur ou login)
- `OrganizationValueResolver`: Charge automatiquement l'organization depuis l'URL
- Variable Twig globale `currentOrganization` disponible partout : l'organization de l'URL (`organizationSlug`) si l'utilisateur y a accès, sinon celle de l'utilisateur (ex. sur `/admin`). Un admin qui consulte un client voit donc ce client (titre, liens, heures mensuelles)

### Gestion des Tickets
- CRUD complet sur les tickets
- Statuts et priorités
- Attribution à des utilisateurs
- Organisation par organisations (multi-tenant)
- Admins peuvent éditer/supprimer tous les tickets (via `TicketVoter`)

### Commentaires
- CRUD sur les commentaires (threads)
- Liés aux tickets
- Modification/suppression par l'auteur
- Admins peuvent éditer/supprimer tous les commentaires (via `CommentVoter`)

### Pièces Jointes
- Upload de fichiers sur tickets et commentaires
- Types supportés: images, PDF, Word, Excel, texte, archives
- Stockage sécurisé dans `var/uploads/attachments/`
- Taille max: 10 MB par fichier
- Upload multiple supporté
- Affichage avec preview (images) ou icônes (autres fichiers)
- Téléchargement et suppression sécurisés
- Gestion lors de l'édition (tickets et commentaires)
- Partial réutilisable `_attachment_card.html.twig`

### Heures mensuelles (forfait de maintenance)
- `Organization.monthlyHours` (null = pas de forfait, facturé au réel → rien n'est affiché) et `overageTolerance` (%, défaut 30)
- `MonthlyQuotaCalculator` → `App\Model\MonthlyQuota` (état `QuotaState` : ok / tolerated / exceeded), heures des activités par `workDate` sur le mois civil
- Twig : `monthly_quota(organization)` et filtre `format_hours` (2.25 → 2h15)
- Client : jauge toujours visible sur la liste des tickets, la tolérance n'est jamais montrée ; message neutre (liste + création de ticket) seulement au-delà de la tolérance
- Admin : jauge avec tolérance (liste tickets, fiche organization), colonne « This month » dans la liste des organizations
- Email : `QuotaExceededSubscriber` (Doctrine) prévient les membres de l'organization + admins une seule fois par mois au dépassement de la tolérance (`quotaExceededNotifiedMonth`)
- Traductions ICU : toute clé avec syntaxe ICU (`{date, date, ...}`) ou placeholder `{x}` passé sans accolades doit aussi exister dans `messages+intl-icu.en.yaml`, sinon la locale `en` affiche la syntaxe brute

### Serveurs MCP (Claude)
- `/mcp` (serveur `default`, outils `App\Mcp\Tma\`) : tickets et activités, tokens de portée `tma`
- `/mcp/notes` (serveur `notes`, outils `App\Mcp\Notes\`) : notes org-mode personnelles, tokens de portée `notes`, voir `docs/org-notes.md`
- Un token (`ApiToken.scope`) n'ouvre que le serveur de sa portée ; un client OAuth par connecteur claude.ai (`OAUTH_CLIENT_*`, `OAUTH_NOTES_CLIENT_*`)
- `capture_note` : `OrgCapture` (rendu org, validation) + `OrgNotesGit` (un fichier par note dans `inbox/` du clone `ORG_NOTES_DIR`, commit + push)
- Lecture seule (`list_notes`, `outline_note`, `read_note`, `search_notes`) : `OrgNotesReader`, tout chemin passe par `resolve()` ; `ORG_NOTES_EXCLUDE` ; journal dans le canal monolog `org_notes`

### Système de Notifications Email
- **NotificationSubscriber**: EventSubscriber Doctrine écoutant `postPersist` et `postUpdate`
- **EmailService**: Service centralisé pour l'envoi d'emails avec templates Twig
- **Templates emails**: Layout réutilisable (`emails/_layout.html.twig`) + templates spécifiques
- **Emails envoyés**:
  - Nouveau ticket créé → Notifie admins + users de l'organization (sauf créateur)
  - Nouveau commentaire → Notifie admins + users de l'organization (sauf auteur)
  - Changement de status → Notifie admins + users de l'organization (sauf utilisateur qui modifie)
  - Heures mensuelles dépassées au-delà de la tolérance → admins + users de l'organization, une fois par mois
- **Détection changements**: UnitOfWork Doctrine pour tracker les modifications (changeSet)
- **Exclusion**: L'auteur de l'action est exclu des notifications (via `Security::getUser()`)
- **URLs dynamiques**: Utilisation de `url()` Twig pour générer des liens absolus (emails)
- **Langue**: Templates en anglais (cohérence avec l'app, traductions possibles plus tard)

## Structure du Projet

### Entités Principales
- `User`: Utilisateurs du système (avec roles)
- `Organization`: Multi-tenant (avec slug unique auto-généré)
- `Ticket`: Tickets avec titre, description, statut, priorité
- `Comment`: Commentaires liés aux tickets
- `Attachment`: Pièces jointes (polymorphique: Ticket OU Comment)
- `Status`: Statuts des tickets
- `Priority`: Priorités des tickets

### Services
- `FileUploader`: Gestion de l'upload de fichiers (slug + uniqid, validation MIME types)
- `EmailService`: Service d'envoi d'emails avec Symfony Mailer et templates Twig

### Event Subscribers
- `NotificationSubscriber`: Écoute les événements Doctrine (`postPersist`, `postUpdate`) pour envoyer des emails automatiquement

### Security (Voters)
- `OrganizationVoter`: Admins peuvent accéder à toutes les organizations
- `TicketVoter`: Gère TICKET_EDIT, TICKET_RESOLVE et TICKET_DELETE (un membre ne peut supprimer qu'un ticket vide, sans commentaire ni activité ; sinon admin uniquement)
- `CommentVoter`: Gère les permissions COMMENT_EDIT et COMMENT_DELETE (auteur uniquement, sauf admins)

### Twig Extensions
- `OrganizationExtension`: Injecte `currentOrganization` (organization consultée, voir Multi-tenant) globalement dans tous les templates

### Templates (Partials)
- `attachment/_attachment_card.html.twig`: Carte d'attachment réutilisable (tickets et commentaires)
- `comment/_comment_list.html.twig`: Liste des commentaires
- `comment/_comment_form.html.twig`: Formulaire d'ajout de commentaire

### Templates Emails
- `emails/_layout.html.twig`: Layout de base pour tous les emails (header, footer, styles inline)
- `emails/ticket_created.html.twig`: Notification de création de ticket
- `emails/comment_added.html.twig`: Notification d'ajout de commentaire
- `emails/status_changed.html.twig`: Notification de changement de status

### Sécurité
- Fichiers stockés hors de `public/` pour contrôle d'accès
- Vérifications multi-niveaux (organization + voters)
- Admins ont accès à tout via les Voters
- Protection CSRF sur toutes les actions destructives

## Prochaines Features Possibles

### Priorité Haute
1. **Design & UX** (en cours)
   - Améliorer la navigation et le layout
   - Styliser les pages principales (liste tickets, détail ticket)
   - Dashboard utilisateur

### Améliorations
2. Suppression physique automatique des fichiers lors de la suppression d'entités (Doctrine Listener)
3. Pagination sur la liste des tickets
4. Filtres et recherche
5. Lightbox pour les images
6. Preview PDF dans le navigateur

### Production
- Migration vers PostgreSQL/MySQL
- Variables d'environnement
- Optimisations performances

## Notes Techniques
- Projet solo pour ~15 clients max
- Pas besoin d'optimisation prématurée sur l'organisation des fichiers
- Focus sur la simplicité et les fonctionnalités métier
- Architecture multi-tenant avec isolation stricte par organization
- Utilisation de patterns Symfony avancés (ValueResolver, Voters, Twig Extensions)
- Si commentaire dans le code : doit être en anglais

## Apprentissages Récents
- ValueResolver pour injection automatique d'entités depuis l'URL
- Voters pour centraliser la logique de permissions
- Twig Extensions pour variables globales
- Partials Twig pour réutilisabilité du code
- AssetMapper et ImportMap pour gestion des assets frontend
- Bootstrap Icons intégration
- EventSubscriber Doctrine pour déclencher des actions sur les événements du cycle de vie des entités
- UnitOfWork pour tracker les changements sur les entités (changeSet)
- Symfony Mailer avec TemplatedEmail pour emails HTML
- Service Security pour récupérer l'utilisateur connecté
