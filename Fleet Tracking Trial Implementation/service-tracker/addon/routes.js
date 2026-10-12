import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('visits', function () {
        this.route('index', { path: '/' });
        this.route('view', { path: '/:public_id' });
    });
    this.route('vehicles', function () {
        this.route('index', { path: '/' });
        this.route('view', { path: '/:plate' });
    });
    this.route('petty-cash');
    this.route('damage');
    this.route('dashboard');
    this.route('report');
    this.route('catalog');
    this.route('settings');
    this.route('export');
});
