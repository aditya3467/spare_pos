<?php
// Only implemented routes have a URL. Disabled items are never anchors.
return [
    'Overview'=>[['Dashboard','dashboard','/dashboard',null]],
    'Catalogue'=>[['Products','box','/products','products.manage'],['Categories','folder','/categories','owner'],['Brands','layers','/product-masters','owner']],
    'Inventory'=>[['Current Stock','stock','/inventory','inventory.view'],['Stock In','plus','/inventory/in','inventory.stock_in'],['Stock Movements','arrows','/inventory/movements','inventory.movements'],['Stock Adjustments','sliders','/inventory/adjust','inventory.adjust'],['Stock Out','return','/inventory/out','inventory.stock_out']],
    'Purchases'=>[['Suppliers','truck',null,'purchases.manage'],['Purchase Invoices','receipt',null,'purchases.manage'],['Supplier Returns','return',null,'purchases.manage']],
    'Sales'=>[['New Sale / POS','sale','/pos','pos.access'],['Sales History','receipt','/sales','sales.view'],['Sales Returns','return',null,'sales.create']],
    'Parties'=>[['Customers','users',null,'customers.manage'],['Customer Payments','wallet',null,'customers.manage']],
    'Finance'=>[['Expenses','wallet',null,'expenses.manage'],['Cash Closing','receipt',null,'owner']],
    'Reports'=>[['Sales Reports','chart','/sales/report','reports.view'],['Purchase Reports','chart',null,'reports.view'],['Stock Reports','chart',null,'reports.view'],['Profit Reports','chart','/sales/report','reports.view']],
    'Settings'=>[['Shop Details','settings',null,'owner'],['Staff & Permissions','users','/staff','owner'],['Tax & Invoice Settings','sliders','/sales/settings','owner']],
];
