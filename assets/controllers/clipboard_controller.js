import { Controller } from '@hotwired/stimulus';

/*
 * Copie dans le presse-papiers le contenu de la cible `source`
 * (utilisé pour copier la réponse JSON de la page de résultat).
 */
export default class extends Controller {
    static targets = ['source'];

    copy(event) {
        event.preventDefault();
        const button = event.currentTarget;
        navigator.clipboard.writeText(this.sourceTarget.textContent).then(() => {
            const original = button.textContent;
            button.textContent = 'Copié !';
            window.setTimeout(() => {
                button.textContent = original;
            }, 2000);
        });
    }
}
