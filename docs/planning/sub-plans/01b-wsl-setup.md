# 01b — WSL development environment

## Goal

Move local development from the Windows filesystem into WSL 2 (Ubuntu), so the code lives on a Linux filesystem like CI and production. File access and tests get faster, file names become case-sensitive as they are in production, and the Windows-only workarounds from sub-plan 01 can go.

## Depends on

01 (project setup).

## Scope

**In scope**
- Installing Ubuntu in WSL 2 and connecting it to Docker Desktop
- PHP 8.5, Composer and Node 26 inside Ubuntu, for Boost's MCP server, editor tooling and commands run outside Docker
- Git, the GitHub CLI and a GitHub login inside Ubuntu
- Claude Code installed and logged in inside Ubuntu
- Opening the project in Cursor through its WSL extension
- Removing the Windows-only workarounds, and updating the docs that describe them

**Out of scope**
- Creating the GitHub repo, CI and deployment (02)
- Any app features

## Data model

No changes.

## Steps

Steps 1 to 4 are done by hand. Step 5 is done by Claude Code, running inside Ubuntu.

### Before you start

**Move the project with `git clone`, not by copying the folder.** A copy would bring across `vendor` and `node_modules` built for Windows, and file permissions that make no sense on Linux. A clone brings the full history, so everything must be committed first: in the Windows copy, `git status` should be clean and `git stash list` empty.

The only file git doesn't track that needs copying by hand is `.env`. Everything else git ignores (`vendor`, `node_modules`, `public/build`, Wayfinder's generated files, caches) is rebuilt.

**Stop the Windows copy's containers.** In PowerShell, in `D:\projects\booking-app`, run `docker compose down`. Both copies use the same Compose project name (`booking-app`), so they share containers and volumes. Never run both.

### 1. Ubuntu in WSL (PowerShell on Windows)

1. Run `wsl --update` to get the latest WSL.
2. Run `wsl --list --online` to see the available distros, then install the newest Ubuntu LTS, using its name exactly as the list shows it. For Ubuntu 26.04: `wsl --install -d Ubuntu-26.04`.
3. When Ubuntu first starts, choose a Linux username and password. `sudo` asks for this password.
4. Make Ubuntu the default distro (Docker's own distro is the default until now): `wsl --set-default Ubuntu-26.04`.
5. In Docker Desktop, go to **Settings → Resources → WSL integration**, turn on integration for Ubuntu, then **Apply & restart**.

From now on, open Ubuntu from Windows Terminal (which adds an Ubuntu profile) or the Start menu.

### 2. Tools inside Ubuntu

Bring Ubuntu up to date, then check Docker works. If `docker` isn't found, check step 1.5.

```bash
sudo apt update && sudo apt full-upgrade -y
docker version
docker compose version
```

**PHP 8.5**, from Ubuntu 26.04's own packages. The extensions match the Dockerfile's.

```bash
sudo apt install -y php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-xml php8.5-curl \
    php8.5-zip php8.5-intl php8.5-bcmath php8.5-gd unzip
php -v
```

**Composer.** Run the install commands from [getcomposer.org/download](https://getcomposer.org/download/). They include a checksum check that changes with each release, so copy them from the page. Then:

```bash
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

**Node 26**, from NodeSource. Ubuntu's own `nodejs` package is Node 22, and the containers use 26. If NodeSource's instructions have changed, follow the Linux instructions on nodejs.org instead.

```bash
curl -fsSL https://deb.nodesource.com/setup_26.x -o nodesource_setup.sh
sudo -E bash nodesource_setup.sh
sudo apt install -y nodejs
rm nodesource_setup.sh
node -v
```

**Check which PHP and Node run.** `which php` and `which node` should both print paths under `/usr/bin`. WSL adds the Windows PATH to Ubuntu's, so a Windows install could otherwise be picked up.

### 3. Git, GitHub and Claude Code (Ubuntu)

**Git identity.** Use the same name and email as the existing commits. In the Windows copy, `git log -1 --format='%an <%ae>'` shows them. Leave `core.autocrlf` unset, because `.gitattributes` already sets the line endings.

```bash
git config --global user.name "Your Name"
git config --global user.email "you@example.com"
git config --global init.defaultBranch main
```

The repo becomes public in 02, and every commit's author email goes public with it. If you'd rather not show your own email, use the no-reply address from GitHub's email settings instead. The existing commits' author can be changed before the first push, while that's still easy.

**GitHub CLI**, from GitHub's own apt repository. These commands are from the GitHub CLI's Linux install docs:

```bash
(type -p wget >/dev/null || (sudo apt update && sudo apt install wget -y)) \
    && sudo mkdir -p -m 755 /etc/apt/keyrings \
    && out=$(mktemp) && wget -nv -O$out https://cli.github.com/packages/githubcli-archive-keyring.gpg \
    && cat $out | sudo tee /etc/apt/keyrings/githubcli-archive-keyring.gpg > /dev/null \
    && sudo chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg \
    && sudo mkdir -p -m 755 /etc/apt/sources.list.d \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
    && sudo apt update \
    && sudo apt install gh -y
```

Then log in with `gh auth login`:
- Choose **GitHub.com**, then **HTTPS**.
- Say **Yes** to authenticating Git with your GitHub credentials, so `git push` uses this login.
- Choose **Login with a web browser**. If no browser opens, go to https://github.com/login/device in Windows and enter the code shown.

`gh auth status` confirms it worked.

**Claude Code**, with the native installer, which keeps itself up to date:

```bash
curl -fsSL https://claude.ai/install.sh | bash
```

Open a new terminal and run `claude --version`. The first time you run `claude`, it asks you to log in through the browser.

**Your personal Claude Code settings.** The project's own settings are in git, but your personal ones are only on Windows. Copy your settings file, plus each skill folder you made yourself. Leave `synced` behind, because Claude Code manages it. If you had plugins installed, reinstall them with `/plugin`. The project's saved memory is copied in step 5.

```bash
mkdir -p ~/.claude/skills
cp /mnt/c/Users/<windows-user>/.claude/settings.json ~/.claude/
cp -r /mnt/c/Users/<windows-user>/.claude/skills/<skill-name> ~/.claude/skills/
```

### 4. Copy the project and open it in Cursor

**Clone the Windows copy**, then copy `.env`:

```bash
mkdir -p ~/projects
git clone /mnt/d/projects/booking-app ~/projects/booking-app
cd ~/projects/booking-app
git remote remove origin
cp /mnt/d/projects/booking-app/.env .env
```

- `origin` would point at the Windows folder. The GitHub remote is added in 02.
- Optionally, also copy `.claude/settings.local.json`, so you don't have to approve the MCP servers again.

**Open it in Cursor**

1. In Cursor on Windows, open Extensions, search for "WSL" and install the extension published by Anysphere.
2. In Ubuntu, run `cd ~/projects/booking-app && cursor .`. The first time, Cursor installs its server inside Ubuntu. Alternatively, in Cursor, click the remote button in the bottom-left corner, choose **Connect to WSL**, then **File → Open Folder** and pick `~/projects/booking-app`.
3. The bottom-left corner should now say "WSL: Ubuntu-26.04". If it doesn't, Cursor is reading the files through Windows, which is slow.
4. Extensions now run inside Ubuntu, so install the ones you use (PHP, Vue, Laravel, Oxc and so on) again there. Cursor shows an **Install in WSL** button on each.
5. Cursor's built-in terminal now opens in Ubuntu. You can run `claude` there or in Windows Terminal.

Don't open the project through its `\\wsl.localhost\...` path from Windows: it's slow, and editor tools get the paths wrong. Windows Explorer can still browse files at that path, which is handy for copying files in.

### 5. Claude Code finishes the move

In Ubuntu, run `cd ~/projects/booking-app && claude`, then ask it to carry out step 5 of this sub-plan on a `feature/01b-wsl-setup` branch.

1. **Memory.** Copy this project's saved memory from the Windows Claude Code folder (`/mnt/c/Users/<windows-user>/.claude/projects/D--projects-booking-app/memory/`) into its new memory folder.
2. **File ownership.** The dev containers run as `www-data` (UID 33). On the Windows drive, Linux file permissions were only emulated, so this didn't matter. On Linux they're enforced:
   - The container can't write to `storage/` while your user owns it.
   - Files the container creates would belong to `www-data`, so you couldn't edit them. That covers logs, compiled views, Wayfinder's generated files, `public/hot`, and anything made with `php artisan make:`.

   Give `www-data` in the `dev` image your UID and GID through build arguments (defaulting to 1000), as Sail does. The production `php` stage doesn't change.
3. **MCP servers.** Put `.mcp.json` and `.cursor/mcp.json` back to plain `php artisan boost:mcp` and `npx shadcn-vue@latest mcp`, which is what `boost:install` writes. Check that both servers connect in Claude Code and Cursor, and that Boost's docs search and database schema tools work.
4. **Vite file watching.** With the code on Linux, file-change events should reach the container. If HMR works without polling, remove `WATCH_USE_POLLING` and the polling option in `vite.config.ts`.
5. **`vendor` and `node_modules` volumes.** They were kept in container volumes for two reasons: Windows bind mounts were slow, and the host's packages were built for Windows. Neither applies any more. Keep them (separate installs for Ubuntu and the containers) or drop them (one shared install that the editor can also see), and record the choice in the decisions log.
6. **Packages in Ubuntu.** If the volumes stay, run `composer install` and `npm ci` in Ubuntu too, so the editor can see `vendor` and `node_modules`.
7. **Boost.** Run `php artisan boost:update`, so its guidelines pick up PHP 8.5.
8. **Docs.** Update everything that describes the Windows setup:
   - the comments in `docker-compose.yml` and `vite.config.ts`
   - overview section 8
   - the README (developers on Windows work inside WSL 2)
   - `.ai/guidelines/project.md`, then run `boost:update` again
   - `tracking.md`: status, the decisions log, and the Windows notes for the next session

   Mark the `cmd /c` decision as superseded rather than deleting it. Sub-plan 01 stays as it is, as a record of what was done.
9. **Check and time.** Run every check under **Tests**, and compare the PHPUnit time with Windows (42 tests in 7.3s).
10. **Finish.** Commit, merge into `main` locally (there's still no remote until 02), and update `tracking.md`.

## Tests

No new tests. All of these must pass inside WSL:
- `docker compose exec app php artisan test --compact`
- `php artisan test` in Ubuntu, which reaches PostgreSQL at `127.0.0.1:5434`
- Pint, Larastan (`composer types:check`), `npm run check`, `npm run types:check` and `npm run test`
- `docker build --target php .`

## Done when

- [ ] Ubuntu is the default WSL distro, and `docker`, `php -v` (8.5), `composer`, `node -v` (26), `gh auth status` and `claude --version` all work in it
- [ ] The project is at `~/projects/booking-app`, with its full git history and `.env`
- [ ] Cursor opens it through WSL (the bottom-left corner says "WSL: Ubuntu-…")
- [ ] `docker compose up -d` starts everything. http://localhost:8001 works in a Windows browser, registering and logging in work, a password reset email appears in Mailpit, and editing a Vue file updates the page through HMR
- [ ] Files the containers create belong to your Ubuntu user
- [ ] Every check under **Tests** passes
- [ ] Boost's and shadcn-vue's MCP servers connect in Claude Code and Cursor without `cmd /c`
- [ ] The docs no longer describe the Windows setup, and `tracking.md` records the move and the test times
- [ ] `feature/01b-wsl-setup` is merged into `main`
- [ ] Once everything above is ticked, the Windows copy at `D:\projects\booking-app` is deleted

## Notes

- **Keep the Windows copy until the end, but don't work in it.** Once everything under **Done when** is ticked, delete it, so there's only ever one copy.
- **Herd isn't needed for this project any more.** The Herd and Node upgrades in the old notes aren't needed here. Herd can stay for other projects.
- **If `php artisan test` in Ubuntu can't reach the database,** check that Docker Desktop's WSL integration is turned on for Ubuntu.
- **WSL and Docker Desktop share one Linux virtual machine.** If it uses too much memory, set a limit in `%UserProfile%\.wslconfig`.
- **Cursor has open bug reports about its own agent and WSL paths.** Claude Code runs inside Ubuntu, so it isn't affected.
