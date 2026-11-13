import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire d'inscription :
 *  - met à jour le filigrane du champ e-mail selon le profil choisi
 *    (étudiant / professeur),
 *  - contrôle côté front le format de l'e-mail avant soumission et affiche
 *    l'alerte en haut de page avec la règle des formats attendus.
 * Le même contrôle est rejoué côté back-office (contrainte EmailDomain).
 */
export default class extends Controller {
    static targets = ['email', 'profile', 'alert'];

    static values = {
        studentDomain: String,
        teacherDomain: String,
        rule: String,
    };

    connect() {
        this.updatePlaceholder();
    }

    updatePlaceholder() {
        if (!this.hasEmailTarget || !this.hasProfileTarget) return;
        this.emailTarget.placeholder = 'email@' + this.domainForProfile();
    }

    validate(event) {
        const email = this.emailTarget.value.trim();
        if (email === '') {
            return; // champ vide : géré par la validation serveur
        }
        const pattern = new RegExp('^[^@\\s]+@' + this.escapeRegExp(this.domainForProfile()) + '$', 'i');
        if (!pattern.test(email)) {
            event.preventDefault();
            this.showAlert();
            this.emailTarget.focus();
        } else {
            this.hideAlert();
        }
    }

    hideAlert() {
        if (this.hasAlertTarget) {
            this.alertTarget.classList.add('hidden');
        }
    }

    showAlert() {
        this.alertTarget.textContent = this.ruleValue;
        this.alertTarget.classList.remove('hidden');
    }

    domainForProfile() {
        return this.profileTarget.value === 'professeur'
            ? this.teacherDomainValue
            : this.studentDomainValue;
    }

    escapeRegExp(text) {
        return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}
