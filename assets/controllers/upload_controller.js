import { Controller } from '@hotwired/stimulus';

/*
 * Zone de dépôt de fichier pour la page de test d'upload :
 * surbrillance pendant le drag, dépôt natif, et aperçu du fichier choisi.
 */
export default class extends Controller {
    static targets = ['dropzone', 'input', 'label'];

    pick() {
        this.inputTarget.click();
    }

    dragOver(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.add('border-indigo-500', 'bg-indigo-50');
    }

    dragLeave(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('border-indigo-500', 'bg-indigo-50');
    }

    drop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('border-indigo-500', 'bg-indigo-50');
        if (event.dataTransfer.files.length > 0) {
            this.inputTarget.files = event.dataTransfer.files;
            this.showFile(event.dataTransfer.files[0]);
        }
    }

    choose(event) {
        if (event.target.files.length > 0) {
            this.showFile(event.target.files[0]);
        }
    }

    showFile(file) {
        const sizeMo = (file.size / 1024 / 1024).toFixed(2);
        this.labelTarget.textContent = '';
        const strong = document.createElement('strong');
        strong.textContent = file.name;
        const small = document.createElement('span');
        small.className = 'block text-xs text-gray-500 mt-1';
        small.textContent = `${sizeMo} Mo — prêt à envoyer`;
        this.labelTarget.append(strong, small);
    }
}
