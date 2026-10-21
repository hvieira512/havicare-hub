/** O contexto e a navegação do catálogo: a lista, o formulário e a ficha. */
let els;
let ui;

export function initSettingsModels(context) {
    els = context.els;
    ui = context.ui;
}

export function getSettingsModelsRuntime() {
    return { els, ui };
}

/** Desliza o carrossel para um dos painéis: 0 a lista, 1 o modelo novo, 2 a ficha. */
export function showModelsPane(index) {
    bootstrap.Carousel.getOrCreateInstance(els.modelsCarousel, { interval: false, wrap: false, touch: false }).to(index);
}

export function isModelListShown() {
    return els.modelsCarousel.querySelector(".carousel-item").classList.contains("active");
}
