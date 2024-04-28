/**
 * Plugin Content AutoMsg
 * @copyright   Copyright (C) 2024 ConseilGouz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 */
document.addEventListener("DOMContentLoaded", function(){
    // check CG custom classes
    fields = document.querySelectorAll('.view-plugin .hidefield');
    for(var i=0; i< fields.length; i++) {
        let field = fields[i];
        field.parentNode.parentNode.style.display = "none";
    }
    fields = document.querySelectorAll('.view-plugin .clear');
    for(var i=0; i< fields.length; i++) {
        let field = fields[i];
        field.parentNode.parentNode.style.clear = "both";
    }
    fields = document.querySelectorAll('.view-plugin .left');
    for(var i=0; i< fields.length; i++) {
        let field = fields[i];
        field.parentNode.parentNode.style.float = "left";
    }
    fields = document.querySelectorAll('.view-plugin .right');
    for(var i=0; i< fields.length; i++) {
        let field = fields[i];
        field.parentNode.parentNode.style.float = "right";
    }
    fields = document.querySelectorAll('.view-plugin .half');
    for(var i=0; i< fields.length; i++) {
        let field = fields[i];
        field.style.width = "50%";
    }
    fields = document.querySelectorAll('.view-plugin .gridauto');
    for(var i=0; i< fields.length; i++) {
        let field = fields[i];
        field.parentNode.parentNode.style.gridColumn = "auto";
    }
})