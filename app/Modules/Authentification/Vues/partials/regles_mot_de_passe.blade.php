{{--
    Ce que le mot de passe doit contenir, dit sous le champ et coché en direct
    (retest de production : « ajouter une précision sur les types de
    caractères à saisir »). Seule la longueur est exigée par le serveur ; les
    quatre autres règles sont conseillées, et la liste le dit.
--}}
<ul class="regles-mdp" id="regles-mdp" aria-live="polite"
    style="list-style:none; margin:8px 0 0; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:12px; line-height:1.9; color:#64748b;">
    <li data-regle="longueur"><i class="fa-solid fa-circle-xmark"></i> <strong>8 caractères au moins</strong> (obligatoire)</li>
    <li data-regle="majuscule"><i class="fa-solid fa-circle-xmark"></i> une lettre majuscule (A-Z)</li>
    <li data-regle="minuscule"><i class="fa-solid fa-circle-xmark"></i> une lettre minuscule (a-z)</li>
    <li data-regle="chiffre"><i class="fa-solid fa-circle-xmark"></i> un chiffre (0-9)</li>
    <li data-regle="special"><i class="fa-solid fa-circle-xmark"></i> un caractère spécial (! @ # $ % & * ? - _ …)</li>
    <li style="margin-top:4px; font-size:11.5px;">Les accents et les espaces sont acceptés. Évitez votre nom et votre date de naissance.</li>
</ul>
<script>
(function () {
    var champ = document.getElementById('password');
    var liste = document.getElementById('regles-mdp');
    if (!champ || !liste) return;
    var regles = {
        longueur: function (v) { return v.length >= 8; },
        majuscule: function (v) { return /[A-ZÀ-ÖØ-Þ]/.test(v); },
        minuscule: function (v) { return /[a-zß-öø-ÿ]/.test(v); },
        chiffre: function (v) { return /[0-9]/.test(v); },
        special: function (v) { return /[^A-Za-zÀ-ÖØ-öø-ÿ0-9\s]/.test(v); }
    };
    function verifier() {
        var v = champ.value;
        Object.keys(regles).forEach(function (cle) {
            var li = liste.querySelector('[data-regle="' + cle + '"]');
            var ok = regles[cle](v);
            li.style.color = ok ? '#047857' : '#64748b';
            li.querySelector('i').className = ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
        });
    }
    champ.addEventListener('input', verifier);
    verifier();
})();
</script>
