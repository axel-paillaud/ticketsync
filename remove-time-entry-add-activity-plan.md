# Plan: Refactorisation TimeEntry → Activity

## Objectif
Transformer le système de facturation horaire (stressant pour le client) en système de tracking d'activité pour modèle forfaitaire.

## Changements principaux
- **Renommer** : `TimeEntry` → `Activity`
- **Conserver** : `hours` (tracking factuel), `description`, `workDate`, timestamps, relations
- **Supprimer** : `billedHours`, `hourlyRateSnapshot`, `billedAmount` + `Organization.hourlyRate`
- **Supprimer** : `AlertSubscriber` (dépend de billedAmount)

---

## Étape 1 : Migration Base de Données

### Créer nouvelle migration
**Action** : Générer migration Doctrine pour renommer table et supprimer colonnes

**Fichier** : `migrations/Version{timestamp}_RenameTimeEntryToActivity.php`

**Opérations up()** :
```sql
-- 1. Supprimer ancienne table (données non critiques en prod)
DROP TABLE time_entry;

-- 2. Créer nouvelle table activity (vide)
CREATE TABLE activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    description CLOB NOT NULL,
    hours NUMERIC(5, 2) NOT NULL,
    work_date DATE NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    ticket_id INTEGER NOT NULL,
    created_by_id INTEGER NOT NULL,
    organization_id INTEGER NOT NULL,
    CONSTRAINT FK_AC74095A700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_AC74095AB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_AC74095A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE INITIALLY IMMEDIATE
);

-- 3. Créer index
CREATE INDEX IDX_AC74095A700047D2 ON activity (ticket_id);
CREATE INDEX IDX_AC74095AB03A8386 ON activity (created_by_id);
CREATE INDEX IDX_AC74095A32C8A3DE ON activity (organization_id);

-- 4. Supprimer hourlyRate de organization (recréation simplifiée)
CREATE TEMPORARY TABLE __temp__organization AS
SELECT id, name, slug, is_active, created_at, email, phone, address
FROM organization;

DROP TABLE organization;

CREATE TABLE organization (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(128) NOT NULL,
    is_active BOOLEAN NOT NULL,
    created_at DATETIME NOT NULL,
    email VARCHAR(180) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address CLOB DEFAULT NULL
);

INSERT INTO organization SELECT * FROM __temp__organization;
DROP TABLE __temp__organization;
CREATE UNIQUE INDEX UNIQ_C1EE637C989D9B62 ON organization (slug);
```

**Note** : Perte de données time_entry acceptée (projet en dev, 1 seule entrée en prod non critique). Migration down() non implémentée (pas nécessaire).

---

## Étape 2 : Entités et Repository

### 2.1 Renommer TimeEntry → Activity
**Fichier** : `src/Entity/TimeEntry.php`

- Renommer classe en `Activity`
- Mettre à jour `#[ORM\Table(name: 'activity')]`
- Mettre à jour repository : `ActivityRepository::class`
- **Supprimer propriétés** : `billedHours`, `hourlyRateSnapshot`, `billedAmount`
- **Supprimer méthodes** : `getBilledHours()`, `setBilledHours()`, `getHourlyRateSnapshot()`, `setHourlyRateSnapshot()`, `getBilledAmount()`, `setBilledAmount()`, `calculateBilledHours()`, `calculateBilledAmount()`
- Mettre à jour docblocks `inversedBy: 'activities'`

### 2.2 Renommer TimeEntryRepository → ActivityRepository
**Fichier** : `src/Repository/TimeEntryRepository.php`

- Renommer classe en `ActivityRepository`
- Mettre à jour imports et docblocks
- **Supprimer méthode** : `calculateMonthlyTotal()` (dépend de billedAmount)
- **Conserver** : `findByTicket()`, `findByOrganization()`, `findAllOrderedByDate()`, `findByDateRange()`

### 2.3 Mettre à jour relations dans autres entités

**Fichier** : `src/Entity/Ticket.php`
- Relation : `timeEntries` → `activities`
- Type : `Collection<int, Activity>`
- Méthodes : `getActivities()`, `addActivity()`, `removeActivity()`
- `#[ORM\OneToMany(targetEntity: Activity::class, ...)]`

**Fichier** : `src/Entity/User.php`
- Idem : `timeEntries` → `activities`

**Fichier** : `src/Entity/Organization.php`
- Relation : `timeEntries` → `activities`
- **Supprimer** : propriété `hourlyRate`, `getHourlyRate()`, `setHourlyRate()`

---

## Étape 3 : Formulaire et Voter

### 3.1 Renommer TimeEntryType → ActivityType
**Fichier** : `src/Form/TimeEntryType.php`

- Renommer classe en `ActivityType`
- Mettre à jour `data_class: Activity::class`
- **Conserver champs** : `description`, `hours`, `workDate` (aucun changement)

### 3.2 Renommer TimeEntryVoter → ActivityVoter
**Fichier** : `src/Security/Voter/TimeEntryVoter.php`

- Renommer classe en `ActivityVoter`
- Constantes : `TIMEENTRY_EDIT` → `ACTIVITY_EDIT`, `TIMEENTRY_DELETE` → `ACTIVITY_DELETE`
- `supports()` : vérifier `Activity` au lieu de `TimeEntry`

---

## Étape 4 : Contrôleurs

### 4.1 TicketController
**Fichier** : `src/Controller/TicketController.php`

**Imports** :
- `use App\Entity\Activity;`
- `use App\Form\ActivityType;`
- `use App\Security\Voter\ActivityVoter;`

**Méthodes** :
- `newTimeEntry()` → `newActivity()` - Route : `app_activity_new`, path : `/activity/new`
- `editTimeEntry()` → `editActivity()` - Route : `app_activity_edit`, path : `/activity/{activityId}/edit`
- `deleteTimeEntry()` → `deleteActivity()` - Route : `app_activity_delete`, path : `/activity/{activityId}/delete`

**Supprimer ces lignes** (dans `newActivity()` et `editActivity()`) :
```php
// SUPPRIMER ces 3 lignes :
$activity->setHourlyRateSnapshot(...);
$activity->setBilledHours($activity->calculateBilledHours());
$activity->setBilledAmount($activity->calculateBilledAmount());
```

**Mettre à jour** : Voters constants, variables `$timeEntry` → `$activity`

### 4.2 AdminController
**Fichier** : `src/Controller/AdminController.php`

- Méthode : `timeEntries()` → `activities()`
- Route : `app_admin_activities`, path : `/activities`
- Import : `ActivityRepository`
- **Supprimer** : Calculs `totalBilled` et affichage `billedAmount`
- **Conserver** : `totalHours`, groupement par mois

---

## Étape 5 : Suppression complète du système d'alertes de seuil

### 5.1 Supprimer AlertSubscriber
**Fichier** : `src/EventSubscriber/AlertSubscriber.php`

**Action** : Supprimer complètement le fichier
- Le système d'alertes de seuil mensuel n'a plus de sens sans facturation

### 5.2 Nettoyer User entity
**Fichier** : `src/Entity/User.php`

**Supprimer propriétés** (lignes 67-71) :
- `private ?float $monthlyAlertThreshold = null;`
- `private bool $alertThresholdEnabled = false;`

**Supprimer méthodes** :
- `getMonthlyAlertThreshold()`
- `setMonthlyAlertThreshold()`
- `isAlertThresholdEnabled()`
- `setAlertThresholdEnabled()`

### 5.3 Nettoyer UserProfileType form
**Fichier** : `src/Form/UserProfileType.php`

**Supprimer champs** (lignes 27-44) :
- `alertThresholdEnabled`
- `monthlyAlertThreshold`

**Conserver uniquement** : `firstName`, `lastName`

### 5.4 Nettoyer EmailService
**Fichier** : `src/Service/EmailService.php`

**Supprimer méthode** (ligne 86-101) :
- `sendThresholdExceededAlert()`

### 5.5 Supprimer template email
**Fichier** : `templates/emails/threshold_exceeded.html.twig`

**Action** : Supprimer complètement le fichier

### 5.6 Migration base de données
**Ajouter dans la migration** : Suppression des colonnes de la table `user`

```sql
-- Supprimer colonnes d'alerte dans user
CREATE TEMPORARY TABLE __temp__user AS
SELECT id, email, roles, password, created_at, first_name, last_name, organization_id, is_verified
FROM user;

DROP TABLE user;

CREATE TABLE user (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    email VARCHAR(180) NOT NULL,
    roles CLOB NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    organization_id INTEGER NOT NULL,
    is_verified BOOLEAN NOT NULL,
    CONSTRAINT FK_8D93D64932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)
);

INSERT INTO user SELECT * FROM __temp__user;
DROP TABLE __temp__user;
CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email);
```

---

## Étape 6 : Templates

### 6.1 Renommer et mettre à jour templates

**Renommer répertoire** : `templates/time_entry/` → `templates/activity/`

**Fichier** : `templates/activity/_activity_list.html.twig` (ex: `_time_entry_list.html.twig`)
- Variables : `timeEntry` → `activity`, `ticket.timeEntries` → `ticket.activities`
- **Supprimer affichage** : badges `billedHours`, section `hourlyRateSnapshot`, section `billedAmount`
- **Conserver** : date, hours, description, boutons edit/delete
- Routes : `app_activity_edit`, `app_activity_delete`
- Voters : `ACTIVITY_EDIT`, `ACTIVITY_DELETE`

**Fichier** : `templates/activity/new.html.twig`
- Variable : `activity`
- Titre/breadcrumb : "Add Activity" au lieu de "Log Time"
- **Supprimer** : Affichage du taux horaire de l'organization

**Fichier** : `templates/activity/edit.html.twig`
- Variable : `activity`
- **Supprimer** : Affichage du `hourlyRateSnapshot`

**Fichier** : `templates/admin/activities.html.twig` (ex: `time_entries.html.twig`)
- Variables : `activities`, `activitiesByMonth` (au lieu de `entriesByMonth`)
- **Supprimer colonnes table** : "Rate", "Billed Amount", "Total Billed"
- **Conserver** : Date, Ticket, Organization, Description, Hours, Logged By, Actions
- Summary cards : retirer "Total Billed", conserver "Total Hours"

**Fichier** : `templates/ticket/show.html.twig`
- Mettre à jour `include 'activity/_activity_list.html.twig'`
- Route : `app_activity_new`
- Labels : "Time Entries" → "Activities"

---

## Étape 7 : Exécution et Vérification

### Ordre d'exécution
1. Créer migration et l'exécuter : `php bin/console doctrine:migrations:migrate`
2. Modifier entités (TimeEntry, Ticket, User, Organization)
3. Modifier Repository et Voter
4. Modifier Form
5. Modifier Controllers
6. Supprimer/désactiver AlertSubscriber
7. Renommer et modifier templates
8. Clear cache : `php bin/console cache:clear`

### Tests de vérification
- [ ] Migration exécutée sans erreur
- [ ] Table `activity` existe, `time_entry` n'existe plus
- [ ] Organization n'a plus `hourly_rate`
- [ ] Création d'activité fonctionne (sans erreurs billing)
- [ ] Édition d'activité fonctionne
- [ ] Suppression d'activité fonctionne
- [ ] Page admin activities s'affiche correctement
- [ ] Aucune référence à "TimeEntry" dans le code

---

## Fichiers Critiques (20 fichiers)

### Entities (4)
- `src/Entity/TimeEntry.php` → renommer en `Activity.php`
- `src/Entity/Ticket.php`
- `src/Entity/User.php` ⚠️ aussi pour suppression alertes
- `src/Entity/Organization.php`

### Repository & Security (3)
- `src/Repository/TimeEntryRepository.php` → renommer en `ActivityRepository.php`
- `src/Form/TimeEntryType.php` → renommer en `ActivityType.php`
- `src/Security/Voter/TimeEntryVoter.php` → renommer en `ActivityVoter.php`

### Forms & Services (2)
- `src/Form/UserProfileType.php` - SUPPRIMER champs alertes
- `src/Service/EmailService.php` - SUPPRIMER méthode sendThresholdExceededAlert

### Controllers (2)
- `src/Controller/TicketController.php`
- `src/Controller/AdminController.php`

### EventSubscriber (1)
- `src/EventSubscriber/AlertSubscriber.php` - SUPPRIMER

### Templates (6)
- `templates/time_entry/_time_entry_list.html.twig` → `activity/_activity_list.html.twig`
- `templates/time_entry/new.html.twig` → `activity/new.html.twig`
- `templates/time_entry/edit.html.twig` → `activity/edit.html.twig`
- `templates/admin/time_entries.html.twig` → `admin/activities.html.twig`
- `templates/ticket/show.html.twig`
- `templates/emails/threshold_exceeded.html.twig` - SUPPRIMER

### Migration (1)
- `migrations/Version{timestamp}_RenameTimeEntryToActivity.php` - CRÉER

---

## Risques & Considérations

1. **Migration SQLite complexe** : Table recréation nécessaire, tester en dev
2. **Perte de données billing** : `billedHours`, `hourlyRateSnapshot`, `billedAmount` perdues définitivement (acceptable)
3. **AlertSubscriber** : Système d'alertes supprimé, vérifier si `User` a des colonnes liées à nettoyer
4. **Routes cassées** : Tous les liens/formulaires doivent pointer vers nouvelles routes
5. **Cache** : Bien clear après modifications

---

## Estimation
- Migration création (activity + organization + user) : 45 min
- Entités & Repository : 1h
- Forms, Voters, Controllers : 1h
- EventSubscriber + système alertes : 30 min
- Templates : 1h30
- Tests & vérification : 1h
- **Total** : ~5h30 de refactoring complet
