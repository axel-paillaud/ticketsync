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
- Variable Twig globale `currentOrganization` disponible partout

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

### Système de Notifications Email
- **NotificationSubscriber**: EventSubscriber Doctrine écoutant `postPersist` et `postUpdate`
- **EmailService**: Service centralisé pour l'envoi d'emails avec templates Twig
- **Templates emails**: Layout réutilisable (`emails/_layout.html.twig`) + templates spécifiques
- **Emails envoyés**:
  - Nouveau ticket créé → Notifie admins + users de l'organization (sauf créateur)
  - Nouveau commentaire → Notifie admins + users de l'organization (sauf auteur)
  - Changement de status → Notifie admins + users de l'organization (sauf utilisateur qui modifie)
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
- `TicketVoter`: Gère les permissions TICKET_EDIT et TICKET_DELETE
- `CommentVoter`: Gère les permissions COMMENT_EDIT et COMMENT_DELETE (auteur uniquement, sauf admins)

### Twig Extensions
- `OrganizationExtension`: Injecte `currentOrganization` globalement dans tous les templates

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
- Projet solo pour ~300 clients max
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
