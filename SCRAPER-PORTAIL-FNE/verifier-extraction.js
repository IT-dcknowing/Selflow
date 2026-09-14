/**
 * Vérification de l'extraction, sans toucher au portail.
 *
 *   node verifier-extraction.js
 *
 * Une page factice reproduit les pièges de la vraie page Paramétrage : libellés
 * portés par un `label[for]`, par un `label` englobant, par un `aria-label` ou
 * par un simple voisin ; une clé d'API en clair dans un champ texte ordinaire ;
 * un interrupteur ARIA au lieu d'une case à cocher ; une liste de pagination
 * qui n'a rien à faire dans la fiche ; des libellés sans accents ni casse.
 *
 * Ce fichier ne remplace pas un relevé réel — il vérifie que ce qui a été
 * corrigé le reste. C'est la mémoire de ce qui a été constaté une fois.
 */

'use strict';

const { chromium } = require('playwright');
const { releverLaFiche, CLES_PORTAIL } = require('./fne.js');

const PAGE_PARAMETRAGE = `
  <h2>Compte</h2>

  <label for="email">Email</label>
  <input id="email" type="text" value="it.dcknowing@gmail.com">

  <!-- Libellé sans accent ni casse : doit tomber sur « Téléphone » -->
  <label for="tel">TELEPHONE</label>
  <input id="tel" type="text" value="2722421443">

  <!-- Le secret : champ texte ordinaire, jamais consigné -->
  <label for="apikey">API Key</label>
  <input id="apikey" type="text" value="sk_live_CECI_NE_DOIT_PAS_SORTIR">

  <button>Télécharger la documentation de l'API</button>

  <h2>Entreprise - Facture Normalisée Électronique</h2>

  <!-- Libellé porté par un label englobant -->
  <label>Adresse <input type="text" value="8XVQ+29Q"></label>

  <!-- Libellé porté par aria-label -->
  <input type="text" aria-label="Commune" value="COCODY">

  <!-- Libellé porté par un voisin -->
  <div><span class="form-label">Quartier</span><input type="text" value="RIVIERA II AFRICAINE"></div>

  <label for="cad">Référence Cadastrale</label>
  <input id="cad" type="text" value="*">

  <label for="idu">IDU</label>
  <input id="idu" type="text" value="*">

  <label for="prop">Propriétaire du local professionnel de l'entreprise</label>
  <input id="prop" type="text" value="">

  <!-- Apostrophe typographique et deux-points : doit tomber sur la clé du référentiel -->
  <label for="sticker">Sticker : solde d’alerte</label>
  <input id="sticker" type="text" value="5000">

  <label for="banque">Références bancaires</label>
  <input id="banque" type="text" value="">

  <!-- Interrupteur ARIA : invisible pour un scraper qui ne lit que les inputs -->
  <div><span class="form-label">Timbre de quittance</span>
    <button role="switch" aria-checked="true"></button></div>

  <label for="bapa">Bordereau d'achat de produits agricoles</label>
  <input id="bapa" type="checkbox" checked>

  <label for="pied">Pied de page des factures</label>
  <textarea id="pied"></textarea>

  <label for="mentions">Factures autres mentions</label>
  <textarea id="mentions"></textarea>

  <!-- Un champ que le portail ajouterait demain : conservé tel quel -->
  <label for="nouveau">Régime d'imposition</label>
  <input id="nouveau" type="text" value="RSI">

  <!-- Interface du tableau : hors sujet -->
  <label for="pagination">Lignes par page</label>
  <select id="pagination"><option value="10" selected>10</option></select>
  <input type="search" aria-label="Recherche" value="abc">

  <!-- Le logo : jamais relevé -->
  <label for="logo">Logo</label>
  <input id="logo" type="file">
`;

const PAGE_INCONNUE = `
  <label for="a">Bienvenue</label><input id="a" type="text" value="x">
  <label for="b">Se souvenir de moi</label><input id="b" type="checkbox">
`;

let echecs = 0;

function verifier(intitule, condition, constate) {
  if (condition) {
    console.log(`  ok   ${intitule}`);
    return;
  }
  echecs++;
  console.error(`  ECHEC ${intitule}`);
  if (constate !== undefined) console.error(`        constaté : ${JSON.stringify(constate)}`);
}

(async () => {
  const navigateur = await chromium.launch({ headless: true });
  const page = await navigateur.newPage();

  try {
    console.log('\nPage Paramétrage complète');
    await page.setContent(PAGE_PARAMETRAGE);
    const { fiche, nombreReconnus, inconnus } = await releverLaFiche(page);

    verifier(
      'les quatorze clés du référentiel sont toutes écrites',
      CLES_PORTAIL.every(cle => cle in fiche),
      CLES_PORTAIL.filter(cle => !(cle in fiche))
    );
    verifier('les quatorze sont reconnues', nombreReconnus === 14, nombreReconnus);

    verifier(
      "la clé d'API ne sort pas de la page",
      !JSON.stringify(fiche).includes('sk_live_CECI_NE_DOIT_PAS_SORTIR'),
      Object.keys(fiche).filter(c => /api/i.test(c))
    );
    verifier(
      "le bouton de documentation n'est pas un champ",
      !Object.keys(fiche).some(c => c.includes('documentation')),
      Object.keys(fiche)
    );

    verifier(
      'un libellé sans accent ni casse tombe sur la clé du référentiel',
      fiche['Téléphone'] === '2722421443',
      fiche['Téléphone']
    );
    verifier(
      "l'apostrophe typographique et les deux-points ne cassent rien",
      fiche["Sticker : solde d'alerte"] === '5000',
      fiche["Sticker : solde d'alerte"]
    );

    verifier(
      "l'interrupteur ARIA est lu comme un booléen",
      fiche['Timbre de quittance'] === true,
      fiche['Timbre de quittance']
    );
    verifier(
      'la case à cocher est lue comme un booléen',
      fiche["Bordereau d'achat de produits agricoles"] === true,
      fiche["Bordereau d'achat de produits agricoles"]
    );

    verifier('le label englobant est résolu', fiche['Adresse'] === '8XVQ+29Q', fiche['Adresse']);
    verifier("l'aria-label est résolu", fiche['Commune'] === 'COCODY', fiche['Commune']);
    verifier('le voisin porteur du libellé est résolu', fiche['Quartier'] === 'RIVIERA II AFRICAINE', fiche['Quartier']);

    verifier('un champ vide vaut null', fiche['Références bancaires'] === null, fiche['Références bancaires']);
    verifier("l'étoile du portail est transmise telle quelle", fiche['IDU'] === '*', fiche['IDU']);

    verifier(
      "un champ inédit du portail n'est pas perdu",
      fiche["Régime d'imposition"] === 'RSI' && inconnus.includes("Régime d'imposition"),
      inconnus
    );
    verifier(
      "la pagination et la recherche restent hors de la fiche",
      !Object.keys(fiche).some(c => /par page|recherche/i.test(c)),
      Object.keys(fiche)
    );
    verifier(
      "le champ d'envoi de fichier reste hors de la fiche",
      !('Logo' in fiche),
      Object.keys(fiche)
    );

    console.log('\nPage méconnaissable');
    await page.setContent(PAGE_INCONNUE);
    let leve = false;
    try {
      await releverLaFiche(page);
    } catch (erreur) {
      leve = /méconnaissable/.test(erreur.message);
    }
    verifier("une page qui n'est pas Paramétrage fait échouer le relevé", leve);
  } finally {
    await navigateur.close();
  }

  console.log('');
  if (echecs) {
    console.error(`${echecs} vérification(s) en échec.`);
    process.exitCode = 1;
  } else {
    console.log('Toutes les vérifications passent.');
  }
})().catch(erreur => {
  console.error(erreur);
  process.exitCode = 1;
});
