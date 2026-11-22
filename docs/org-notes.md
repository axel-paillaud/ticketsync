# Notes org-mode via MCP (`/mcp/notes`)

Second serveur MCP, séparé de TicketSync : Claude (claude.ai, souvent en vocal
depuis le téléphone) y enregistre des notes dans un dépôt git dédié,
`org-inbox` sur GitHub. Les notes org elles-mêmes (`~/Org`, dépôt `org-notes`)
ne sont jamais copiées sur le serveur : il n'en voit rien et ne peut rien en
lire.

## Principe

- `capture_note` écrit **un fichier par note** dans `inbox/` du clone serveur
  d'`org-inbox`, commit (auteur `ticketsync-bot`) puis push. Le serveur ne fait
  qu'ajouter ou réécrire ses fichiers, le poste ne fait que les supprimer :
  `pull --rebase` ne peut pas entrer en conflit.
- Le PHP écrit toute la syntaxe org (titre, `:PROPERTIES:`, lignes `CLOCK:`) ;
  Claude ne fournit que le texte et des heures ISO 8601 (Europe/Paris).
- Correction d'une note pas encore triée : `replaceId` réécrit le même fichier.
- Si le push échoue (GitHub injoignable), la note reste commitée sur le serveur
  et part avec la note suivante ; l'outil le signale dans `warnings`.
- Sur le poste, clone dans `~/OrgInbox` (hors de `~/Org`) :
  `M-x axel/org-inbox-merge` (config Doom) fait un pull, ajoute les notes à la
  fin de `~/Org/refile.org` (enregistré avant toute suppression : un plantage
  donne un doublon, jamais une perte), les supprime d'`org-inbox` puis pousse.
  `org-auto-pull.sh` (toutes les 10 min) récupère les nouvelles notes et
  notifie ; `org-auto-push.sh` repousse `~/OrgInbox` si le push a échoué.

## Sécurité

- Endpoint et connecteur claude.ai à part : un token a une portée (`tma` ou
  `notes`, colonne `api_token.scope`) et n'ouvre que le serveur de sa portée.
  Une conversation qui lit des tickets clients (contenu écrit par des tiers) ne
  doit pas avoir le connecteur notes activé en même temps.
- Un client OAuth par connecteur : c'est le client qui fixe la portée du token,
  le code est lié au client, et le paramètre `resource` (RFC 8707) doit
  correspondre.
- Admin uniquement (`access_control` sur `^/mcp`, plus une vérification dans l'outil).
- Le serveur n'a qu'un clone d'`org-inbox` et une deploy key limitée à ce
  dépôt : une compromission du serveur ou une clé volée n'expose que les notes
  dictées pas encore triées.
- Pas de protection de branche : GitHub ne la propose pas sur un dépôt privé
  avec un compte gratuit. Au pire, une clé volée peut effacer les notes pas
  encore triées.

## Mise en place sur le serveur (ps-nas)

Le conteneur monte `/var/www/ticketsync` au même chemin, et Apache tourne sous
l'uid 1000 (`axel` sur l'hôte) : tout est créé en tant qu'`axel`, sur l'hôte.

1. Clé SSH dédiée :

   ```sh
   D=/var/www/ticketsync/shared/org-inbox-ssh
   mkdir -m 700 $D
   ssh-keygen -t ed25519 -N '' -C ticketsync-bot-inbox -f $D/id_ed25519
   ssh-keyscan -t ed25519 github.com > $D/known_hosts
   ssh-keygen -lf $D/known_hosts   # comparer avec les empreintes publiées par GitHub
   cat $D/id_ed25519.pub
   ```

2. GitHub : dépôt privé `org-inbox` (un `README.md` et `inbox/.gitkeep`),
   puis la deploy key en écriture :

   ```sh
   gh repo deploy-key add id_ed25519.pub -R axel-paillaud/org-inbox --allow-write --title ticketsync-bot
   ```

3. Clone :

   ```sh
   SSH="ssh -i $D/id_ed25519 -o IdentitiesOnly=yes -o UserKnownHostsFile=$D/known_hosts -o StrictHostKeyChecking=yes"
   GIT_SSH_COMMAND="$SSH" git clone git@github.com:axel-paillaud/org-inbox.git /var/www/ticketsync/shared/org-inbox
   cd /var/www/ticketsync/shared/org-inbox
   git config core.sshCommand "$SSH"
   ```

   Vérifier depuis le conteneur :
   `docker exec -u www-data ticketsync_app git -C /var/www/ticketsync/shared/org-inbox pull`

4. Variables d'environnement (dans `.env.production.local`, comme `OAUTH_CLIENT_*`) :

   ```sh
   ORG_NOTES_DIR=/var/www/ticketsync/shared/org-inbox
   OAUTH_NOTES_CLIENT_ID=...       # php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   OAUTH_NOTES_CLIENT_SECRET=...   # idem, différent de OAUTH_CLIENT_*
   ```

   Puis recréer le conteneur (un restart ne relit pas `env_file`) :
   `cd /var/www/ticketsync/current && docker compose -p ticketsync up -d --force-recreate`.

5. Déployer (migration `api_token.scope` ; les tokens existants passent en `tma`).

6. claude.ai > *Settings > Connectors > Add custom connector* :
   URL `https://ticketsync.axelweb.fr/mcp/notes`, et dans les options avancées
   le client id et le secret `OAUTH_NOTES_*`. La page de consentement indique
   « écrire de nouvelles notes dans l'inbox ».

Pour un client en ligne de commande (Claude Code) :
`php bin/console app:api-token:create <email> <nom> --scope=notes`.
