{{--
    La question posée à la facturation d'un achat (retest d'octobre 2026) :
    la facture normalisée d'un fournisseur revient par le relevé du portail
    et passe en écriture. Saisie aussi ici, elle passerait deux fois.
--}}
<div style="margin-bottom:18px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px 16px;">
    <label style="font-weight:700; font-size:12.5px; color:#1e3a8a; display:block; margin-bottom:8px;">
        Cette facture sera-t-elle déclarée par le fournisseur sur la plateforme FNE ? <span style="color:#dc2626">*</span>
    </label>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <label style="flex:1; min-width:150px; border:1px solid #cbd5e1; border-radius:8px; padding:9px 10px; cursor:pointer; background:#fff; font-size:12.5px;">
            <input type="radio" name="declaree_par_fournisseur" value="1" required>
            <strong>Oui</strong> — elle sera certifiée par le fournisseur
        </label>
        <label style="flex:1; min-width:150px; border:1px solid #cbd5e1; border-radius:8px; padding:9px 10px; cursor:pointer; background:#fff; font-size:12.5px;">
            <input type="radio" name="declaree_par_fournisseur" value="0" required>
            <strong>Non</strong> — facture non normalisée
        </label>
    </div>
    <p style="font-size:11.5px; color:#1d4ed8; margin:8px 0 0; line-height:1.5;">
        <strong>Oui</strong> : cette saisie reste informative ; l'écriture comptable sera passée à partir de la facture
        certifiée récupérée de la FNE. Le règlement, lui, s'écrit dès maintenant.
        <strong>Non</strong> : l'écriture est passée tout de suite.
    </p>
</div>
