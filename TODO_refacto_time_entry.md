# TODO - Refactorisation Time Entry → Activity

Reliquats de l'ancien système de facturation/time entry à corriger.

## Problèmes Critiques 🔴

### 1. TicketController.php - Variable name
**Fichier:** `src/Controller/TicketController.php`
**Lignes:** 639, 695
**Problème:** Utilise `'timeEntry' => $activity` au lieu de `'activity' => $activity`
**Impact:** Les templates vont chercher une variable `timeEntry` qui n'existe pas

```php
// Ligne 639 (méthode newActivity)
'timeEntry' => $activity,  // ❌ À remplacer par 'activity' => $activity

// Ligne 695 (méthode editActivity)
'timeEntry' => $activity,  // ❌ À remplacer par 'activity' => $activity
```

### 2. activity/edit.html.twig - Erreur de syntaxe
**Fichier:** `templates/activity/edit.html.twig`
**Ligne:** 62
**Problème:** Propriété manquante après le pipe `{{ activity.|number_format(2) }}`
**Impact:** Erreur de template au chargement de la page

```twig
<strong>{{ 'Original Hourly Rate'|trans }}:</strong> {{ activity.|number_format(2) }} €/h
```

## Problèmes Moyens 🟡

### 3. Texte d'aide sur la facturation
**Fichiers:**
- `templates/activity/new.html.twig` (ligne 49)
- `templates/activity/edit.html.twig` (ligne 49)

**Problème:** Référence à l'arrondi des heures facturées (plus pertinent dans le nouveau système)

```twig
{{ 'Billed hours will be rounded up to nearest 0.5h'|trans }}
```

**Action:** Supprimer cette ligne d'aide

### 4. Section "Original Hourly Rate"
**Fichier:** `templates/activity/edit.html.twig`
**Lignes:** 60-68
**Problème:** Section entière sur le taux horaire d'origine (concept de facturation obsolète)

```twig
{# Original Billing Info (Read-only) #}
<div class="alert alert-warning mt-3">
    <strong>{{ 'Original Hourly Rate'|trans }}:</strong> {{ activity.|number_format(2) }} €/h
    ...
</div>
```

**Action:** Supprimer toute cette section

### 5. Nommage du modal de suppression
**Fichier:** `templates/activity/_activity_list.html.twig`
**Lignes:** 43, 61
**Problème:** Modal nommé `deleteTimeEntryModal` au lieu de `deleteActivityModal`

```twig
Line 43: data-bs-target="#deleteTimeEntryModal{{ activity.id }}"
Line 61: <div class="modal fade" id="deleteTimeEntryModal{{ activity.id }}" tabindex="-1">
```

**Action:** Renommer en `deleteActivityModal{{ activity.id }}`

## Traductions ℹ️

**Fichier:** `translations/messages+intl-icu.fr.yaml`

Les anciennes traductions liées au système de facturation sont toujours présentes :
- Line 13-15: Time entry messages
- Line 83-98: Time entries section
- Line 161-179: Time entry details
- Line 177: 'Original Hourly Rate'

**Note:** Peut-être à garder pour compatibilité ou à nettoyer selon les besoins.

## Ordre de correction recommandé

1. ✅ **Critique 1 & 2** : Corriger TicketController.php et activity/edit.html.twig (bloque l'utilisation)
2. ✅ **Moyen 4** : Supprimer la section "Original Hourly Rate" (contient l'erreur de syntaxe)
3. ✅ **Moyen 3** : Supprimer les textes d'aide obsolètes
4. ✅ **Moyen 5** : Renommer les modals pour cohérence
5. ⚠️ **Traductions** : Décider si on nettoie ou on garde pour compatibilité
