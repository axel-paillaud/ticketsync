# TicketSync - Résumé du Projet

## Description
Système de gestion de tickets pour gérer les demandes clients. Projet personnel d'apprentissage Symfony utilisé en production.

## Stack Technique
- **Framework**: Symfony 7.3
- **Base de données**: SQLite (dev)
- **Frontend**: Twig + Bootstrap 5 + Turbo
- **PHP**: 8.2+

## Fonctionnalités Actuelles

### Authentification
- Système de connexion/inscription
- Gestion des utilisateurs

### Gestion des Tickets
- CRUD complet sur les tickets
- Statuts et priorités
- Attribution à des utilisateurs
- Organisation par organisations (multi-tenant)

### Commentaires
- CRUD sur les commentaires (threads)
- Liés aux tickets
- Modification/suppression par l'auteur uniquement

### Pièces Jointes (Récemment Ajouté)
- Upload de fichiers sur tickets et commentaires
- Types supportés: images, PDF, Word, Excel, texte, archives
- Stockage sécurisé dans `var/uploads/attachments/`
- Taille max: 10 MB par fichier
- Upload multiple supporté

## Structure du Projet

### Entités Principales
- `User`: Utilisateurs du système
- `Organization`: Multi-tenant
- `Ticket`: Tickets avec titre, description, statut, priorité
- `Comment`: Commentaires liés aux tickets
- `Attachment`: Pièces jointes (polymorphique: Ticket OU Comment)
- `Status`: Statuts des tickets
- `Priority`: Priorités des tickets

### Services
- `FileUploader`: Gestion de l'upload de fichiers (slug + uniqid, validation MIME types)

### Sécurité
- Fichiers stockés hors de `public/` pour contrôle d'accès
- Accès restreint par organisation
- Admins ont accès à tout

## À Faire Prochainement
1. Affichage des attachments dans les templates
2. Contrôleur de téléchargement sécurisé avec vérification des droits
3. Support des attachments lors de l'édition de tickets
4. Support des attachments dans les commentaires
5. Suppression physique des fichiers lors de la suppression d'entités (Doctrine Listener)

## Notes
- Projet solo pour ~300 clients max
- Pas besoin d'optimisation prématurée sur l'organisation des fichiers
- Focus sur la simplicité et les fonctionnalités métier
