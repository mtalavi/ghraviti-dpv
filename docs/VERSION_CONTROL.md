# =============================================================================
# DPV Hub - Git Version Control & Deployment Guide
# =============================================================================

## 📋 Overview

This project uses **Semantic Versioning** (MAJOR.MINOR.PATCH) and automated 
deployment to Coolify via GitHub Actions.

---

## 🚀 Quick Start - Creating a Release

### Windows (PowerShell)
```powershell
# Bug fix release (1.0.0 -> 1.0.1)
.\scripts\release.ps1 patch

# New feature release (1.0.0 -> 1.1.0)
.\scripts\release.ps1 minor

# Breaking change release (1.0.0 -> 2.0.0)
.\scripts\release.ps1 major
```

### Linux/Mac (Bash)
```bash
chmod +x scripts/release.sh

./scripts/release.sh patch   # Bug fix
./scripts/release.sh minor   # New feature
./scripts/release.sh major   # Breaking change
```

---

## 📁 Files Structure

| File | Purpose |
|------|---------|
| `VERSION` | Current version number |
| `CHANGELOG.md` | Release history |
| `.github/workflows/deploy.yml` | Auto-deploy to Coolify |
| `scripts/release.ps1` | Windows release script |
| `scripts/release.sh` | Linux/Mac release script |
| `cli/migrate.php` | Database migration runner |
| `migrations/` | Database migration files |

---

## 🔧 GitHub Secrets Setup

Add these secrets in GitHub repo → Settings → Secrets:

| Secret | Description |
|--------|-------------|
| `COOLIFY_WEBHOOK_URL` | Coolify webhook URL for deployment |
| `COOLIFY_TOKEN` | Coolify API token |
| `SERVER_HOST` | Server IP/hostname |
| `SERVER_USER` | SSH username |
| `SERVER_SSH_KEY` | SSH private key for deployment |
| `APP_PATH` | Path to app on server (e.g., `/var/www/dpvhub`) |

---

## 🗄️ Database Migrations

### Creating a Migration

1. Create file in `migrations/` with format:
   ```
   YYYY_MM_DD_HHMMSS_description.php
   ```
   
2. Example migration:
   ```php
   <?php
   $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
   ```

### Running Migrations

```bash
php cli/migrate.php
```

Migrations run automatically on each Coolify deployment.

---

## 🔄 Deployment Flow

```
1. Run release script (patch/minor/major)
      ↓
2. VERSION file updated
      ↓
3. CHANGELOG.md updated
      ↓
4. Git commit + tag created
      ↓
5. Pushed to GitHub
      ↓
6. GitHub Actions triggered
      ↓
7. Coolify receives webhook
      ↓
8. App deployed + migrations run
```

---

## 📌 Version History Commands

```bash
# View all releases
git tag --list

# Checkout specific version
git checkout v1.2.0

# Return to latest
git checkout main

# View version history
git log --oneline --decorate
```

---

## ⚠️ Important Notes

1. **Never commit** `.env` or sensitive config files
2. **Always test** locally before creating a release
3. **Update CHANGELOG.md** with meaningful descriptions
4. **Database backups** should be done before major releases
