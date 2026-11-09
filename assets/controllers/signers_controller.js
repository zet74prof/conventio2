import { Controller } from '@hotwired/stimulus';

/*
 * Gestion dynamique des signataires du formulaire de test YouTrust.
 *
 * Le <template> embarque une ligne de signataire rendue par Twig avec les
 * placeholders __INDEX__ (dans les name/id) et __NUM__ (numéro affiché) ;
 * add() les remplace par l'index courant avant insertion dans le DOM.
 */
export default class extends Controller {
    static targets = ['container', 'template', 'count'];

    static values = {
        count: { type: Number, default: 0 },
    };

    add() {
        const html = this.templateTarget.innerHTML
            .replaceAll('__INDEX__', String(this.countValue))
            .replaceAll('__NUM__', String(this.countValue + 1));
        this.containerTarget.insertAdjacentHTML('beforeend', html);
        this.countValue++;
        this.countTarget.value = String(this.countValue);
    }

    remove(event) {
        const box = event.currentTarget.closest('[data-signers-target~="signer"]');
        if (!box) {
            return;
        }
        if (this.countValue <= 1) {
            window.alert('Au moins un signataire est requis.');
            return;
        }
        box.remove();
        this.countValue--;
        this.countTarget.value = String(this.countValue);
        this.renumber();
    }

    // Réindexe les champs restants pour garder des name="signer_<i>_..." continus.
    renumber() {
        this.containerTarget.querySelectorAll('[data-signers-target~="signer"]').forEach((box, i) => {
            box.dataset.index = String(i);
            box.querySelectorAll('input, select').forEach((input) => {
                input.name = input.name.replace(/signer_\d+/, `signer_${i}`);
                input.id = input.id.replace(/signer_\d+/, `signer_${i}`);
            });
            box.querySelectorAll('[data-signers-target~="num"]').forEach((el) => {
                el.textContent = String(i + 1);
            });
        });
    }
}
