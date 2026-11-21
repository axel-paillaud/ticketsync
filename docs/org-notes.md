# Notes org-mode via MCP (`/mcp/notes`)

Second serveur MCP, séparé de TicketSync : Claude (claude.ai, souvent en vocal
depuis le téléphone) y enregistre des notes dans le dépôt git des notes org
(`org-notes` sur GitHub).

## Principe

- `capture_note` écrit **un fichier par note** dans `inbox/` du clone serveur,
  commit (auteur `ticketsync-bot`) puis push. Le serveur ne touche jamais à
  autre chose qu'`inbox/` : `pull --rebase` ne peut pas entrer en conflit.
- Le PHP écrit toute la syntaxe org (titre, `:PROPERTIES:`, lignes `CLOCK:`) ;
  Claude ne fournit que le texte et des heures ISO 8601 (Europe/Paris).
- Correction d'une note pas encore triée : `replaceId` réécrit le même fichier.
- Si le push échoue (GitHub injoignable), la note reste commitée sur le serveur
  et part avec la note suivante ; l'outil le signale dans `warnings`.
- Lecture seule de tous les `.org` : `list_notes` (titre, date du dernier
  commit), `outline_note` (titres avec numéros de ligne), `read_note` (fichier
  ou sous-arbre d'un titre, découpé en pages de 60 ko), `search_notes` (tous
  les mots sur une même ligne, sans tenir compte de la casse ni des accents).
  Avant de lire, le serveur fait un `pull` (au plus une fois par minute).
- Sur le poste : `M-x axel/org-inbox-merge` (config Doom) fait un pull puis
  déplace les notes d'`inbox/` à la fin de `refile.org`. `org-auto-push.sh`
  fait un `pull --rebase` avant de pousser.

## Sécurité

- Endpoint et connecteur claude.ai à part : un token a une portée (`tma` ou
  `notes`, colonne `api_token.scope`) et n'ouvre que le serveur de sa portée.
  Une conversation qui lit des tickets clients (contenu écrit par des tiers) ne
  doit pas avoir le connecteur notes activé en même temps.
- Un client OAuth par connecteur : c'est le client qui fixe la portée du token,
  le code est lié au client, et le paramètre `resource` (RFC 8707) doit
  correspondre.
- Lecture : chemins relatifs uniquement, pas de `..` ni de segment caché
  (`.git`, `.env`…), extension `.org`, liens symboliques refusés (le chemin
  résolu doit rester dans le clone). `ORG_NOTES_EXCLUDE` (globs séparés par des
  virgules, ex. `tma.org,freelance/*`) masque des fichiers : un fichier exclu
  est indiscernable d'un fichier absent, et n'apparaît ni dans la liste ni
  dans la recherche.
- Admin uniquement (`access_control` sur `^/mcp`, plus une vérification dans l'outil).
- Deploy key limitée au dépôt `org-notes` ; protection de la branche `main`
  sur GitHub (force-push et suppression interdits) pour qu'une clé volée ne
  puisse pas réécrire l'historique.

## Mise en place sur le serveur (ps-nas)

Le conteneur monte `/var/www/ticketsync` au même chemin, et Apache tourne sous
l'uid 1000 (`axel` sur l'hôte) : tout est créé en tant qu'`axel`, sur l'hôte.

1. Clé SSH dédiée :

   ```sh
   D=/var/www/ticketsync/shared/org-notes-ssh
   mkdir -m 700 $D
   ssh-keygen -t ed25519 -N '' -C ticketsync-bot -f $D/id_ed25519
   ssh-keyscan -t ed25519 github.com > $D/known_hosts
   ssh-keygen -lf $D/known_hosts   # comparer avec les empreintes publiées par GitHub
   cat $D/id_ed25519.pub
   ```

2. GitHub, dépôt `org-notes` :
   - *Settings > Deploy keys > Add deploy key* : la clé publique, titre
     `ticketsync-bot`, **Allow write access** coché ;
   - *Settings > Rules > Rulesets* : ruleset sur `main` avec *Restrict deletions*
     et *Block force pushes* (pas de règle « pull request obligatoire », elle
     bloquerait le bot).

3. Clone :

   ```sh
   SSH="ssh -i $D/id_ed25519 -o IdentitiesOnly=yes -o UserKnownHostsFile=$D/known_hosts -o StrictHostKeyChecking=yes"
   GIT_SSH_COMMAND="$SSH" git clone git@github.com:axel-paillaud/org-notes.git /var/www/ticketsync/shared/org-notes
   cd /var/www/ticketsync/shared/org-notes
   git config core.sshCommand "$SSH"
   ```

   Vérifier depuis le conteneur :
   `docker exec -u www-data ticketsync_app git -C /var/www/ticketsync/shared/org-notes pull`

4. Variables d'environnement (dans `.env.production.local`, comme `OAUTH_CLIENT_*`) :

   ```sh
   ORG_NOTES_DIR=/var/www/ticketsync/shared/org-notes
   ORG_NOTES_EXCLUDE=              # facultatif, ex. tma.org,freelance/*
   OAUTH_NOTES_CLIENT_ID=...       # php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   OAUTH_NOTES_CLIENT_SECRET=...   # idem, différent de OAUTH_CLIENT_*
   ```

   Puis recréer le conteneur (un restart ne relit pas `env_file`) :
   `docker compose -p ticketsync up -d --force-recreate`.

5. Déployer (migration `api_token.scope` ; les tokens existants passent en `tma`).

6. claude.ai > *Settings > Connectors > Add custom connector* :
   URL `https://ticketsync.axelweb.fr/mcp/notes`, et dans les options avancées
   le client id et le secret `OAUTH_NOTES_*`. La page de consentement indique
   « écrire de nouvelles notes dans l'inbox ».

Pour un client en ligne de commande (Claude Code) :
`php bin/console app:api-token:create <email> <nom> --scope=notes`.
