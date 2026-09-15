#!/bin/bash
# =============================================================================
# deploy-seed.sh — Script de peuplement massif Selflow sur serveur distant
# =============================================================================
# USAGE :
#   1. Mettre ce fichier à la racine du projet Selflow sur le serveur
#   2. Lancer : bash deploy-seed.sh
#
# PREREQUIS :
#   - PHP 8.2+ installé sur le serveur
#   - Connexion à la base MySQL configurée dans .env
#   - Artisan accessible (projet Laravel)
# =============================================================================

set -e  # Stopper en cas d'erreur

echo ""
echo "======================================================"
echo "  🚀 SELFLOW — Déploiement Seed Massif Entreprises"
echo "======================================================"
echo ""

# --- Vérifications préliminaires ---
if [ ! -f "artisan" ]; then
    echo "❌ ERREUR : Ce script doit être lancé depuis la racine du projet Selflow."
    exit 1
fi

if [ ! -f ".env" ]; then
    echo "❌ ERREUR : Fichier .env introuvable. Veuillez le configurer d'abord."
    exit 1
fi

echo "✅ Projet Selflow détecté."

# Ce script PURGE toute la base. Il n'a rien à faire sur un serveur de
# production : la commande le refuse d'ailleurs d'elle-même.
if grep -qE '^APP_ENV=production' .env; then
    echo "❌ REFUSÉ : APP_ENV=production. Ce script efface toutes les données."
    exit 1
fi
echo ""

# --- Confirmation obligatoire ---
echo "⚠️  ATTENTION : Cette opération va SUPPRIMER TOUTES LES DONNÉES existantes"
echo "    et recréer un jeu de données complet pour les deux entreprises."
echo ""
read -p "    Tapez 'OUI' pour continuer : " CONFIRMATION

if [ "$CONFIRMATION" != "OUI" ]; then
    echo ""
    echo "⛔ Opération annulée."
    exit 0
fi

echo ""
echo "📦 Étape 1/4 — Mise en maintenance de l'application..."
php artisan down --render="errors::503" || true

echo ""
echo "🔄 Étape 2/4 — Nettoyage des caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

echo ""
echo "📊 Étape 3/4 — Exécution des migrations (si nécessaire)..."
php artisan migrate --force

echo ""
echo "🌱 Étape 4/4 — Peuplement massif de la base de données..."
echo "    (Cela peut prendre 2 à 5 minutes selon la performance du serveur...)"
echo ""
php artisan selflow:seed-massif --force

echo ""
echo "🔁 Remise en service de l'application..."
php artisan up

echo ""
echo "🎉 ==========================================================="
echo "   Peuplement terminé avec succès !"
echo ""
echo "   Deux entreprises disponibles :"
echo "   - DIST-CI DISTRIBUTION SARL     → admin1@selflow-demo.ci"
echo "   - KIRÉNA AGRO-INDUSTRIE SARL    → admin2@selflow-demo.ci"
echo "   (Responsables/Caissiers : voir la console ci-dessus pour la liste complète"
echo "   des emails générés ; le mot de passe est affiché une seule fois ci-dessus)"
echo ""
echo "   Super Administrateur   → mot de passe de SUPERADMIN_PASSWORD, ou affiché une fois ci-dessus"
echo ""
echo "   Données générées PAR ENTREPRISE :"
echo "   ✔  4 Points de Vente + logo (URL externe placeholder)"
echo "   ✔  Utilisateurs (Admin + Responsable + 2 Caissiers par PDV)"
echo "   ✔  Plan Comptable SYSCOHADA (32 comptes)"
echo "   ✔  10 Codes Journaux (VTE, ACH, OD, CAI, BNI, SGBCI, OM, MTN, MOOV, WAVE)"
echo "   ✔  100 Clients et 100 Fournisseurs (NCC, RCCM, régime fiscal)"
echo "   ✔  100 Catégories × 100 produits (= 10 000 articles, avec photo placeholder)"
echo "   ✔  100 Ventes directes (comptant total/partiel, crédit, banque total/partiel)"
echo "   ✔  100 Achats directs (mêmes modes de règlement)"
echo "   ✔  20 cycles Devis → Bon de Commande → Bon de Livraison → Facture"
echo "   ✔  15 Avoirs Client + 15 Avoirs Fournisseur"
echo "   ✔  Règlements différés sur ~60% des créances/dettes à crédit"
echo "   ✔  100 Fiches Techniques (Recettes) + 100 Ordres de Production"
echo "   ✔  Stocks physiques par PDV (avec ~5% de ruptures volontaires)"
echo "   ✔  20 Transferts internes + 15 commandes fournisseur à réceptionner"
echo "   ✔  Écritures comptables via le vrai moteur ComptabiliteService"
echo "      (mêmes règles que l'application : pas de 411/401 fictif sur le"
echo "      comptant intégral, code journal séparé par ligne, N° Saisie réel)"
echo ""
echo "   ⚠️  Voir le rapport d'anomalies affiché en fin d'exécution ci-dessus"
echo "      pour les points de vigilance (mot de passe superadmin en clair,"
echo "      images externes nécessitant Internet, simulation FNE, etc.)"
echo "==============================================================="
echo ""
