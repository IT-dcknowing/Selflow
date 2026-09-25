{{--
    Export PDF des documents (vente et achat), a inclure a l'interieur d'un
    bloc <script>. La vue appelante doit definir `nomFichierPdf()`, qui renvoie
    le nom du fichier propose, sans extension.
--}}
/**
 * Impression / enregistrement PDF du seul document affiche.
 *
 * La capture d'ecran (html2canvas) a ete abandonnee : elle rasterisait la
 * page, d'ou un texte flou, une mise en page calquee sur la largeur de la
 * fenetre — donc deformee — et plusieurs secondes d'attente. Le moteur
 * d'impression du navigateur fait le meme travail en vectoriel, au format A4
 * exact et sans delai.
 *
 * Le document est recopie dans un cadre isole plutot qu'imprime depuis la
 * page elle-meme. Deux raisons :
 *   - rien de l'interface (barre laterale, boutons) n'a besoin d'etre masque,
 *     et la structure de la page peut varier d'un ecran a l'autre sans risque ;
 *   - l'en-tete que le navigateur ajoute a chaque feuille reprend l'adresse de
 *     la page, d'ou les « 127.0.0.1:8003/admin/ventes/facture » imprimes sur
 *     la facture. Une marge de page nulle supprime cet en-tete ; la marge
 *     utile est alors portee par la feuille elle-meme.
 */
function telechargerPdf() {
    var piece = document.querySelector('.invoice');
    if (!piece) return;

    var titre = (typeof nomFichierPdf === 'function') ? nomFichierPdf() : document.title;

    // Les feuilles de style de la page sont reprises telles quelles : le
    // document doit s'imprimer exactement comme il s'affiche.
    var styles = Array.prototype.map.call(
        document.querySelectorAll('link[rel="stylesheet"], style'),
        function (noeud) { return noeud.outerHTML; }
    ).join('\n');

    var page = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        + '<title>' + titre + '</title>'
        + '<base href="' + document.baseURI + '">'
        + styles
        + '<style>'
        // Marge nulle : c'est ce qui empeche le navigateur d'imprimer son
        // en-tete (adresse de la page, date) et son pied de page (pagination).
        + '@page { size: A4 portrait; margin: 0; }'
        + 'html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }'
        // La marge utile est portee par la feuille, que rien d'autre ne cible.
        + '.feuille { padding: 12mm 10mm; }'
        + '</style></head><body><div class="feuille">'
        + piece.outerHTML
        + '</div></body></html>';

    var cadre = document.createElement('iframe');
    cadre.setAttribute('aria-hidden', 'true');
    cadre.setAttribute('title', titre);
    cadre.style.cssText = 'position:fixed;left:-9999px;top:0;width:210mm;height:297mm;border:0;';

    cadre.onload = function () {
        var fenetre = cadre.contentWindow;
        var termine = false;

        function nettoyer() {
            if (termine) return;
            termine = true;
            fenetre.removeEventListener('afterprint', nettoyer);
            cadre.remove();
        }

        fenetre.addEventListener('afterprint', nettoyer);

        // Laisser au logo et aux polices le temps de s'afficher : imprimer
        // trop tot sortait une facture sans son en-tete graphique.
        setTimeout(function () {
            fenetre.focus();
            fenetre.print();
            // Filet de securite : quelques navigateurs n'emettent jamais
            // `afterprint`, et le cadre resterait alors dans la page.
            setTimeout(nettoyer, 1000);
        }, 300);
    };

    cadre.srcdoc = page;
    document.body.appendChild(cadre);
}

/**
 * Enregistrement du document dans un fichier.
 *
 * « Imprimer / PDF » passe par la boite d'impression du navigateur : c'est une
 * sortie, pas un fichier. Ici, le document est recopie dans une page autonome
 * — ses feuilles de style comprises — et remis au navigateur comme un
 * telechargement. Aucune boite ne s'ouvre, et le fichier porte le numero de la
 * piece.
 *
 * Le format est HTML et non PDF : l'application n'embarque aucun moteur PDF, et
 * en ajouter un (dompdf) engage une dependance et une remise en page complete
 * des documents. Le fichier rendu s'ouvre dans n'importe quel navigateur et
 * s'imprime a l'identique.
 */
function telechargerFichier(selecteur) {
    var piece = document.querySelector(selecteur || '.invoice');
    if (!piece) return;

    var nom = (typeof nomFichierPdf === 'function') ? nomFichierPdf() : document.title;

    var styles = Array.prototype.map.call(
        document.querySelectorAll('link[rel="stylesheet"], style'),
        function (noeud) { return noeud.outerHTML; }
    ).join('\n');

    var page = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        + '<title>' + nom + '</title>'
        + '<base href="' + document.baseURI + '">'
        + styles
        + '<style>@page { size: A4 portrait; margin: 0; }'
        + 'html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }'
        + '.feuille { padding: 12mm 10mm; }</style>'
        + '</head><body><div class="feuille">'
        + piece.outerHTML
        + '</div></body></html>';

    var adresse = URL.createObjectURL(new Blob([page], { type: 'text/html;charset=utf-8' }));
    var lien = document.createElement('a');
    lien.href = adresse;
    lien.download = nom + '.html';
    document.body.appendChild(lien);
    lien.click();
    lien.remove();
    setTimeout(function () { URL.revokeObjectURL(adresse); }, 2000);
}
