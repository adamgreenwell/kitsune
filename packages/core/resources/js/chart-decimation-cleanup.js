/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

(() => {
    const plugins = window.filamentChartJsGlobalPlugins ??= [];
    const id = 'kitsune-decimation-cleanup';

    if (plugins.some((plugin) => plugin.id === id)) return;

    plugins.push({
        id,

        afterDestroy(chart) {
            const decimation = chart.constructor.registry.plugins.get('decimation');

            // Chart.js calls beforeDestroy/afterDestroy. Older Decimation versions expose destroy.
            if (typeof decimation?.destroy === 'function'
                && typeof decimation.beforeDestroy !== 'function'
                && typeof decimation.afterDestroy !== 'function') {
                decimation.destroy(chart);
            }
        },
    });
})();
