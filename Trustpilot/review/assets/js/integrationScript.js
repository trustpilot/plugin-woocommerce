window.addEventListener("message", this.receiveSettings);

function receiveSettings(e) {
    if (e.origin === location.origin){
        return receiveInternalData(e);
    }
    if (e.origin !== checkProtocol(trustpilot_integration_settings.TRUSTPILOT_INTEGRATION_APP_URL)) {
        return;
    }
    const data = e.data;
    if (typeof data !== 'string') {
        return;
    }
    if (data.startsWith('sync:') || data.startsWith('showPastOrdersInitial:')) {
        const split = data.split(':');
        const action = {};
        action['action'] = 'handle_past_orders';
        action[split[0]] = split[1];
        this.submitPastOrdersCommand(action);
    } else if (data.startsWith('resync')) {
        const action = {};
        action['action'] = 'handle_past_orders';
        action['resync'] = 'resync';
        this.submitPastOrdersCommand(action);
    } else if (data.startsWith('issynced')) {
        const action = {};
        action['action'] = 'handle_past_orders';
        action['issynced'] = 'issynced';
        this.submitPastOrdersCommand(action);
    } else if (data.startsWith('check_product_skus')) {
        const split = data.split(':');
        const action = {};
        action['action'] = 'check_product_skus';
        action['skuSelector'] = split[1];
        this.submitCheckProductSkusCommand(action);
    } else if (data === 'signup_data') {
        this.sendSignupData();
    } else if (data === 'update') {
        updateplugin();
    } else if (data === 'reload') {
        reloadSettings();
    } else if (data && tryParseJson(data) && JSON.parse(data).TrustBoxPreviewMode) {
        TrustBoxPreviewMode(data);
    } else {
        handleJSONMessage(data);
    }
}

function receiveInternalData(e) {
    const data = e.data;
    if (data && typeof data === 'string' && tryParseJson(data)) {
        const jsonData = JSON.parse(data);
        if (jsonData && jsonData.type === 'updatePageUrls') {
            submitSettings(jsonData);
        }
        if (jsonData && jsonData.type === 'newTrustBox') {
            submitSettings(jsonData);
        }
    }
}

function handleJSONMessage(data) {
    if (tryParseJson(data)) {
        const parsedData = JSON.parse(data);
        if (parsedData.window) {
            this.updateIframeSize(parsedData);
        } else if (parsedData.type === 'submit') {
            this.submitSettings(parsedData);
        } else if (parsedData.trustbox) {
            const iframe = document.getElementById('trustbox_preview_frame');
            iframe.contentWindow.postMessage(JSON.stringify(parsedData.trustbox), "*");
        }
    }
}

function encodeSettings(settings) {
    let encodedString = '';
    for (const setting in settings) {
        encodedString += `${setting}=${settings[setting]}&`
    }
    return encodedString.substring(0, encodedString.length - 1);
}

function getFormValues(form) {
    let values = {};
    for (const el in form.elements) {
        const element = form.elements[el];
        if (element.nodeName === 'INPUT') {
            values[element.name] = element.value;
        }
    }
    return values;
}

function tryParseJson(str) {
    try {
        JSON.parse(str);
    } catch (e) {
        return false;
    }
    return true;
}

function submitPastOrdersCommand(data) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status >= 400) {
                console.log(`callback error: ${xhr.response} ${xhr.status}`);
            } else {
                sendPastOrdersInfo(xhr.response);
            }
        }
    };
    xhr.send(encodeSettings(data));
}

function submitCheckProductSkusCommand(data) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status >= 400) {
                console.log(`callback error: ${xhr.response} ${xhr.status}`);
            } else {
                const iframe = document.getElementById('configuration_iframe');
                iframe.contentWindow.postMessage(xhr.response, iframe.dataset.transfer);
            }
        }
    };
    xhr.send(encodeSettings(data));
}

function sendSignupData() {
    const data = {
        action: 'get_signup_data'
    };

    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status >= 400) {
                console.log(`callback error: ${xhr.response} ${xhr.status}`);
            } else {
                const iframe = document.getElementById('configuration_iframe');
                const message = JSON.stringify({trustpilot_signup_data: xhr.response});
                iframe.contentWindow.postMessage(message, iframe.dataset.transfer);
            }
        }
    };
    xhr.send(encodeSettings(data));
}

function updateplugin() {
    const data = {
        action: 'update_trustpilot_plugin'
    };
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send(encodeSettings(data));
}

function TrustBoxPreviewMode(data) {
    const settings = JSON.parse(data);
    const div = document.getElementById('trustpilot-trustbox-preview');
    if (settings.TrustBoxPreviewMode.enable) {
        div.hidden = false;
    } else {
        div.hidden = true;
    }
}

function reloadSettings() {
    const data = {
        action: 'reload_trustpilot_settings'
    };
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status >= 400) {
                console.log(`callback error: ${xhr.response} ${xhr.status}`);
            } else {
                const iframe = document.getElementById('configuration_iframe');
                iframe.contentWindow.postMessage(xhr.response, iframe.dataset.transfer);
            }
        }
    };
    xhr.send(encodeSettings(data));
}

function submitSettings(parsedData) {
    let data = { action: 'handle_save_changes' };
    if (parsedData.type === 'updatePageUrls') {
        data.pageUrls = encodeURIComponent(JSON.stringify(parsedData.pageUrls));
    } else if (parsedData.type === 'newTrustBox') {
        data.customTrustBoxes = encodeURIComponent(JSON.stringify(parsedData));
    } else {
        data.settings = encodeURIComponent(JSON.stringify(parsedData.settings));
        document.getElementById('trustbox_preview_frame').dataset.settings = btoa(data.settings);
    }
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    // TODO: possibility here to send response to integration app for notification about error/success
    // xhr.onreadystatechange = function () {
    //     if (xhr.readyState === 4) {
    //         if (xhr.status >= 400) {
    //             console.log(`callback error: ${xhr.response} ${xhr.status}`);
    //         } else {
    //             console.log(`callback success: ${xhr.response}`);
    //         }
    //     }
    // };
    xhr.send(encodeSettings(data));
}

function updateIframeSize(settings) {
  const iframe = document.getElementById('configuration_iframe');
  if (iframe) {
    iframe.height=(settings.window.height) + "px";
  }
}

function sendSettings() {
    const iframe = document.getElementById('configuration_iframe');

    const attrs = iframe.dataset;
    const settings = JSON.parse(atob(attrs.settings));

    if (!settings.trustbox) {
        settings.trustbox = {}
    }

    settings.trustbox.pageUrls = JSON.parse(atob(attrs.pageUrls));
    settings.pluginVersion = attrs.pluginVersion;
    settings.source = attrs.source;
    settings.version = attrs.version;
    settings.basis = 'plugin';
    settings.productIdentificationOptions = JSON.parse(attrs.productIdentificationOptions);
    settings.isFromMarketplace = attrs.isFromMarketplace;
    settings.configurationScopeTree = JSON.parse(atob(attrs.configurationScopeTree));
    settings.pluginStatus = JSON.parse(atob(attrs.pluginStatus));

    if (settings.trustbox.trustboxes && attrs.sku) {
        for (trustbox of settings.trustbox.trustboxes) {
            trustbox.sku = attrs.sku;
        }
    }

    if (settings.trustbox.trustboxes && attrs.name) {
        for (trustbox of settings.trustbox.trustboxes) {
            trustbox.name = attrs.name;
        }
    }

    iframe.contentWindow.postMessage(JSON.stringify(settings), checkProtocol(attrs.transfer));
}

function checkProtocol(url) {
    if (url.startsWith('//')) {
        const protocol = window.location.protocol;
        return protocol + url;
    }
    return url;
}

function sendPastOrdersInfo(data) {
    const iframe = document.getElementById('configuration_iframe');
    const attrs = iframe.dataset;

    if (data === undefined) {
        data = attrs.pastOrders;
    }

    iframe.contentWindow.postMessage(data, checkProtocol(attrs.transfer));
}
