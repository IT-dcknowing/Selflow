{{-- Fiche client enrichie d'un espace sur mesure (23/11/2026) : métiers de la beauté. --}}
<div style="grid-column:1/-1; margin-top:4px; padding:12px 14px; border:1px dashed var(--primary-halo, #bfdbfe); border-radius:10px; background:var(--bg3, #f8fafc);">
    <div style="font-weight:800; font-size:13px; color:var(--primary); margin-bottom:10px;"><i class="fas fa-scissors"></i> Préférences du client</div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px;">
        @foreach(\App\Modules\Admin\Modeles\Client::PREFERENCES as $cle => [$libelle, $aide])
            <div class="form-group" style="margin:0; {{ $cle === 'remarques' ? 'grid-column:1/-1;' : '' }}">
                <label class="form-label">{{ $libelle }}</label>
                @if($cle === 'remarques')
                    <textarea name="preferences[{{ $cle }}]" class="form-control" rows="2" placeholder="{{ $aide }}" @if($prefixe === 'edit') data-preference-edit="{{ $cle }}" @endif></textarea>
                @else
                    <input type="text" name="preferences[{{ $cle }}]" class="form-control" placeholder="{{ $aide }}" maxlength="500" @if($prefixe === 'edit') data-preference-edit="{{ $cle }}" @endif>
                @endif
            </div>
        @endforeach
    </div>
</div>
