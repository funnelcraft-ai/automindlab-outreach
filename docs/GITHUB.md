# Putting the code on GitHub, and deploying from it

Keep the repository **private**. This is commercial code, and the history shows how everything works.

The repository root is the app folder itself: what's in the repo is what goes into `public_html` of `outreach.automindlab.io`.

## 1. Create an empty private repository

1. On github.com, click **New repository**.
2. Name it, for example `automindlab-outreach`. Set **Private**.
3. Leave **Add a README**, **.gitignore** and **licence** all unticked. The code already has them, and an empty repo avoids a merge on the first push.
4. Click **Create repository**.

## 2. Upload the code (choose one)

### A. GitHub Desktop (easiest, no commands)

1. Unzip `automindlab-ai-outreach-github-v2.4.1.zip` into a folder such as `Documents/automindlab-outreach`.
2. In GitHub Desktop, choose **File → Add local repository** and pick that folder. When asked, click **create a repository** here (keep the name; Git ignore and licence: *None*, because they're already included).
3. In the **Summary** box, type `Automindlab AI Outreach v2.4.1`, then click **Commit to main**.
4. Click **Publish repository**, keep **Keep this code private** ticked, and choose your account.

### B. Command line

```bash
cd automindlab-outreach                 # the unzipped folder
git init -b main
git add .
git commit -m "Automindlab AI Outreach v2.4.1"
git remote add origin https://github.com/<your-account>/automindlab-outreach.git
git push -u origin main
git tag v2.4.1 && git push origin v2.4.1
```

### C. Browser upload (works, but check the hidden files)

On the empty repository page, click **uploading an existing file** and drag in the *contents* of the unzipped folder.

Files whose names start with a dot are hidden on Mac and Windows, and are easy to leave out. The four `.htaccess` files protect the app's internals, so they must be in the repo. Check that step 3 passes.

## 3. Check the repository

On GitHub, confirm all of these:

- [ ] `.htaccess`, `lib/.htaccess`, `storage/.htaccess` and `vendor/.htaccess` are all present.
- [ ] `.gitignore` is present, and there is **no** `config.php` or `outreach-config.php` anywhere.
- [ ] The **Actions** tab shows **PHP lint** passing (green tick). It also fails if a config file or an API key is ever committed.
- [ ] Optional: **Settings → Branches → Add rule** for `main`, requiring a pull request and the PHP lint check before merging.

## 4. Deploy to Hostinger from GitHub

Hostinger can pull the repo into the site, and redeploy on every push to `main`.

**Before the first deploy:**

- [ ] Back up the database (phpMyAdmin → Export → Quick) and the current site files (File Manager → compress `public_html` → download).
- [ ] Confirm the config file is **outside** the web folder, at `domains/outreach.automindlab.io/outreach-config.php`. Each deploy replaces the files in the deploy folder; a config file outside it can't be touched.

**Connect:**

1. In hPanel, open the website `outreach.automindlab.io` and go to **Advanced → Git**.
2. Click **Connect with GitHub**, and in the popup allow the Hostinger app to access **only** this repository.
3. Select the repository, branch `main`, and set the deploy directory to this subdomain's `public_html`.
4. Click **Deploy** and watch the log.
5. Open `https://outreach.automindlab.io/admin/` and sign in. The database upgrades itself.

**From then on:** every push or merge to `main` deploys automatically. Turn that off in the same Git page if you'd rather deploy by hand with **Redeploy**.

**Notes:**

- `install.php` comes with every deploy. That's safe: once an admin account exists it answers "not found".
- Runtime files in `storage/` (lock, cache, fallback log) may be reset by a deploy. The app recreates them.
- The cron job, database and config file are not part of Git; set them up once (README → Upgrading).
- Web access to `.git/`, `.github/`, `docs/` and the `.md` / `.sql` files is blocked by `.htaccess`.

## 5. Working on it day to day

- Make a branch per change, for example `fix/bounce-alert`, and open a pull request. Merge to `main` only after the PHP lint check passes and someone has tried the change.
- Test changes on a staging copy first, such as a second subdomain with its own database and config, deploying a `staging` branch.
- Bump `APP_VERSION` in `lib/bootstrap.php` and add a `CHANGELOG.md` entry for every release, so browsers load the new CSS/JS. Tag the release (`git tag v2.3.1`).
- Database changes go in a new `migrate_vN()` in `lib/bootstrap.php`, plus the same change in `schema.sql`, with `SCHEMA_VERSION` raised by one.
- Never commit secrets. If one slips in, rotate it at the provider first, then clean the history.
