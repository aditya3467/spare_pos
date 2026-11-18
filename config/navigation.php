<?php
// Only implemented routes have a URL. Disabled items are never anchors.
return [
    'Overview'=>[['Dashboard','dashboard','/dashboard',null]],
    'Catalogue'=>[['Products','box','/products','products.manage'],['Categories','folder','/categories','owner'],['Brands','layers','/product-masters','owner']],
    'Inventory'=>[['Current Stock','stock',null,'inventory.adjust'],['Stock Movements','arrows',null,'inventory.adjust'],['Stock Adjustments','sliders',null,'inventory.adjust']],
    'Purchases'=>[['Suppliers','truck',null,'purchases.manage'],['Purchase Invoices','receipt',null,'purchases.manage'],['Supplier Returns','return',null,'purchases.manage']],
    'Sales'=>[['New Sale / POS','sale',null,'sales.create'],['Sales History','receipt',null,'sales.create'],['Sales Returns','return',null,'sales.create']],
    'Parties'=>[['Customers','users',null,'customers.manage'],['Customer Payments','wallet',null,'customers.manage']],
    'Finance'=>[['Expenses','wallet',null,'expenses.manage'],['Cash Closing','receipt',null,'owner']],
    'Reports'=>[['Sales Reports','chart',null,'reports.view'],['Purchase Reports','chart',null,'reports.view'],['Stock Reports','chart',null,'reports.view'],['Profit Reports','chart',null,'reports.view']],
    'Settings'=>[['Shop Details','settings',null,'owner'],['Staff & Permissions','users','/staff','owner'],['Tax Settings','sliders',null,'owner']],
];
