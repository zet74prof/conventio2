import { Controller } from '@hotwired/stimulus';

/*
 * Contrôleur de démo pédagogique.
 *
 * Il illustre les concepts fondamentaux de Stimulus :
 *  - values   → data-demo-count-value / data-demo-step-value (state réactif)
 *  - targets  → data-demo-target="..." (références vers des éléments du DOM)
 *  - actions  → data-action="click->demo#increment" (liaison événement → méthode)
 *  - cycle de vie → initialize() / connect() / disconnect()
 *
 * Chaque appel est tracé dans le panneau "console" de la page pour
 * visualiser ce qui se passe — naviguez avec Turbo pour voir disconnect().
 */
export default class extends Controller {
    static targets = ['output', 'stepDisplay', 'title', 'log'];

    static values = {
        count: { type: Number, default: 0 },
        step: { type: Number, default: 1 },
    };

    initialize() {
        // Exécuté une seule fois, avant que l'élément ne soit dans le DOM.
        this.trace('initialize() — instance créée, élément pas encore dans le DOM');
    }

    connect() {
        // Exécuté à chaque fois que l'élément entre dans le DOM (y compris après une navigation Turbo).
        this.trace('connect() — contrôleur attaché au DOM');
        this.render();
    }

    disconnect() {
        // Exécuté quand l'élément quitte le DOM : on y nettoie listeners, timers, etc.
        // (ici rien à nettoyer, mais on le trace pour la démo)
        this.trace('disconnect() — élément retiré du DOM, nettoyage effectué');
    }

    increment() {
        this.countValue += this.stepValue;
        this.trace(`increment() → count = ${this.countValue} (step = ${this.stepValue})`);
        this.render();
    }

    decrement() {
        this.countValue -= this.stepValue;
        this.trace(`decrement() → count = ${this.countValue} (step = ${this.stepValue})`);
        this.render();
    }

    reset() {
        this.countValue = 0;
        this.trace('reset() → count = 0');
        this.render();
    }

    randomColor() {
        const hue = Math.floor(Math.random() * 360);
        const color = `hsl(${hue}, 70%, 45%)`;
        this.titleTarget.style.color = color;
        this.trace(`randomColor() → titre repeint en ${color}`);
    }

    updateStep(event) {
        const value = Number(event.target.value);
        if (Number.isFinite(value) && value >= 1) {
            this.stepValue = value;
            this.trace(`updateStep() → step = ${this.stepValue}`);
            this.render();
        }
    }

    render() {
        this.outputTarget.textContent = String(this.countValue);
        this.stepDisplayTarget.textContent = String(this.stepValue);
    }

    trace(message) {
        const time = new Date().toLocaleTimeString('fr-FR', { hour12: false });
        const line = document.createElement('div');
        line.className = 'font-mono text-xs text-green-400';
        line.textContent = `[${time}] ${message}`;
        this.logTarget.appendChild(line);
        this.logTarget.scrollTop = this.logTarget.scrollHeight;
    }
}
