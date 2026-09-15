#!/bin/bash
# ============================================================================
# SCRIPT DE DÉPLOIEMENT SELFLOW — Production : selflow.dc-knowing.com
# Exécuter sur le serveur depuis la racine du projet Selflow
# ============================================================================

set -e
echo "🚀 Déploiement Selflow — Production"

# 1. Copier le .env de production
echo "📋 Configuration de l'environnement..."
cp .env.production .env

# 2. Installer les dépendances
echo "📦 Installation des dépendances..."
composer install --no-dev --optimize-autoloader
npm ci --production

# 3. Migrations
echo "🗄️  Migrations base de données..."
php artisan migrate --force

# 4. Seed des données initiales
echo "🌱 Seeding des données..."
# PLUS DE PEUPLEMENT ICI. `SelflowCompleteSeeder` tournait à chaque
# déploiement, et il commence par VIDER les tables — entreprises, utilisateurs,
# clés FNE, ventes, écritures — pour recréer deux entreprises de démonstration
# et un superadmin au mot de passe tiré au hasard. Chaque mise en ligne
# effaçait donc la production. Les comptes réels se posent par
# `php artisan selflow:importer-comptes` (voir JOURNAL-DE-BORD.md, lot 25).

# Le référentiel de préparamétrage — domaines, métiers, modules, comptes —,
# sans lequel « Configurer mon entreprise » ne peut pas commencer. Il ne
# supprime rien : il crée ce qui manque et met à jour ce qui a changé.
php artisan db:seed --class=ReferentielSeeder --force

# 5. Optimisations
echo "⚡ Optimisations production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5 bis. Dossier des relevés du portail FNE
#
# Le contenu n'est pas versionné — ce sont des données fiscales nominatives —
# donc le dossier n'arrive pas avec le dépôt. Sans lui, `portail-fne:importer`
# s'arrête sur « Le dossier d'import n'existe pas » à chaque passage.
echo "📁 Dossier des relevés du portail FNE..."
mkdir -p storage/app/portail-fne

# 6. Permissions
echo "🔐 Permissions fichiers..."
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

echo ""
echo "✅ Déploiement terminé !"
echo ""
# Plus de tableau de comptes : le déploiement n'en crée aucun, et un mot de
# passe n'a rien à faire dans un script versionné.
echo "Comptes : php artisan selflow:importer-comptes (voir JOURNAL-DE-BORD.md, lot 25)"
echo ""
echo "⚠️  PENSEZ À :"
echo "  1. Modifier DB_PASSWORD dans .env"
echo "  2. Générer une nouvelle APP_KEY : php artisan key:generate"
echo "  3. Poser le planificateur dans le cron, sinon RIEN ne tourne :"
echo "     (reprise des écritures Comptaflow, relevés du portail FNE,"
echo "      rapprochement des rejets — aucune de ces tâches ne s'auto-déclenche)"
echo "     → * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
echo "  4. Configurer le redirect Google OAuth sur Google Console :"
echo "     → Ajouter : https://selflow.dc-knowing.com/auth/callback"
echo "     → JavaScript origins : https://selflow.dc-knowing.com"
