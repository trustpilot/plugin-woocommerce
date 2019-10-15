
function addNamesAndSkusToTrustboxes() {
    const sku = trustbox_settings.sku || '';
    const name = trustbox_settings.name || '';
    const skus = [sku];
    for (var trustBox in trustpilot_trustbox_settings.trustboxes) {
        trustpilot_trustbox_settings.trustboxes[trustBox].sku = skus.join(',');
        trustpilot_trustbox_settings.trustboxes[trustBox].name = name;
    }
}

function renderTrustboxes() {
    const page = trustbox_settings.page || '';
    if (page === "product") {
        addNamesAndSkusToTrustboxes();
    }
    trustpilot_trustbox_settings.trustboxes = trustpilot_trustbox_settings.trustboxes.filter(function(trustBox) {
        return page === trustBox.page
            || trimTrailingSlashes(trustBox.page) === trimTrailingSlashes(location.origin + location.pathname)
            || trustBox.page === btoa(location.origin + location.pathname).toLowerCase();
    });

    if (document.readyState !== "complete") {
        window.addEventListener("load", () => {
             tp('trustBox', trustpilot_trustbox_settings);
         });
    }
    tp('trustBox', trustpilot_trustbox_settings);
}

function trimTrailingSlashes(url) {
    if (typeof url === 'string') {
        return url.replace(/\/+$/, "");
    }
    return url;
}

renderTrustboxes();
