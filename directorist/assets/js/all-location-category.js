/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, { enumerable: true, get: getter });
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 			Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 		}
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// create a fake namespace object
/******/ 	// mode & 1: value is a module id, require it
/******/ 	// mode & 2: merge all properties of value into the ns
/******/ 	// mode & 4: return value when already ns object
/******/ 	// mode & 8|1: behave like require
/******/ 	__webpack_require__.t = function(value, mode) {
/******/ 		if(mode & 1) value = __webpack_require__(value);
/******/ 		if(mode & 8) return value;
/******/ 		if((mode & 4) && typeof value === 'object' && value && value.__esModule) return value;
/******/ 		var ns = Object.create(null);
/******/ 		__webpack_require__.r(ns);
/******/ 		Object.defineProperty(ns, 'default', { enumerable: true, value: value });
/******/ 		if(mode & 2 && typeof value != 'string') for(var key in value) __webpack_require__.d(ns, key, function(key) { return value[key]; }.bind(null, key));
/******/ 		return ns;
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = 7);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./assets/src/js/public/components/categoryLocation.js":
/*!*************************************************************!*\
  !*** ./assets/src/js/public/components/categoryLocation.js ***!
  \*************************************************************/
/*! no static exports found */
/***/ (function(module, exports) {

window.addEventListener('load', function () {
  var $ = jQuery;

  /* Make sure the codes in this file runs only once, even if enqueued twice */
  if (typeof window.directorist_catloc_executed === 'undefined') {
    window.directorist_catloc_executed = true;
  } else {
    return;
  }

  /* Category card grid three width/height adjustment */
  var categoryCard = document.querySelectorAll('.directorist-categories__single--style-three');
  if (categoryCard) {
    categoryCard.forEach(function (elm) {
      var categoryCardWidth = elm.offsetWidth;
      elm.style.setProperty('--directorist-category-box-width', "".concat(categoryCardWidth, "px"));
    });
  }

  /* Taxonomy list dropdown */
  function categoryDropdown(selector, parent) {
    var categoryListToggle = document.querySelectorAll(selector);
    categoryListToggle.forEach(function (item) {
      item.addEventListener('click', function (e) {
        var categoryName = item.querySelector('.directorist-taxonomy-list__name');
        if (e.target !== categoryName) {
          e.preventDefault();
          this.classList.toggle('directorist-taxonomy-list__toggle--open');
        }
      });
    });
  }
  categoryDropdown('.directorist-taxonomy-list-one .directorist-taxonomy-list__toggle', '.directorist-taxonomy-list-one .directorist-taxonomy-list');
  categoryDropdown('.directorist-taxonomy-list-one .directorist-taxonomy-list__sub-item-toggle', '.directorist-taxonomy-list-one .directorist-taxonomy-list');

  // Taxonomy Ajax
  $(document).on('click', '.directorist-categories .directorist-pagination a', function (e) {
    taxonomyPagination(e, $(this), '.directorist-categories');
  });
  $(document).on('click', '.directorist-location .directorist-pagination a', function (e) {
    taxonomyPagination(e, $(this), '.directorist-location');
  });
  function taxonomyPagination(event, clickedElement, containerSelector) {
    event.preventDefault();
    var pageNumber = (clickedElement === null || clickedElement === void 0 ? void 0 : clickedElement.attr('data-page')) || 1;
    var container = clickedElement.closest(containerSelector);
    var containerAttributes = container ? $(container).data('attrs') : {};
    $.ajax({
      url: directorist.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'directorist_taxonomy_pagination',
        nonce: directorist.directorist_nonce,
        page: parseInt(pageNumber),
        attrs: containerAttributes
      },
      beforeSend: function beforeSend() {
        $(containerSelector).addClass('atbdp-form-fade');
      },
      success: function success(response) {
        var _tempContainer$queryS, _tempContainer$queryS2;
        if (!(response !== null && response !== void 0 && response.success)) {
          console.error('Failed to load taxonomy content');
          return;
        }
        var tempContainer = document.createElement('div');
        tempContainer.innerHTML = response.data.content;
        // Handle both category and location wrappers
        var taxonomyWrapper = document.querySelector('.taxonomy-category-wrapper');
        var locationWrapper = document.querySelector('.taxonomy-location-wrapper');
        var updatedCategoryContent = (_tempContainer$queryS = tempContainer.querySelector('.taxonomy-category-wrapper')) === null || _tempContainer$queryS === void 0 ? void 0 : _tempContainer$queryS.innerHTML;
        var updatedLocationContent = (_tempContainer$queryS2 = tempContainer.querySelector('.taxonomy-location-wrapper')) === null || _tempContainer$queryS2 === void 0 ? void 0 : _tempContainer$queryS2.innerHTML;
        if (taxonomyWrapper && updatedCategoryContent) {
          taxonomyWrapper.innerHTML = updatedCategoryContent;
        }
        if (locationWrapper && updatedLocationContent) {
          locationWrapper.innerHTML = updatedLocationContent;
        }
        if (!taxonomyWrapper && !locationWrapper) {
          console.error('Required elements not found in response');
          return;
        }
      },
      complete: function complete() {
        $(containerSelector).removeClass('atbdp-form-fade');
      }
    });
  }
});

/***/ }),

/***/ "./assets/src/js/public/modules/all-location-category.js":
/*!***************************************************************!*\
  !*** ./assets/src/js/public/modules/all-location-category.js ***!
  \***************************************************************/
/*! no exports provided */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _components_categoryLocation__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../components/categoryLocation */ "./assets/src/js/public/components/categoryLocation.js");
/* harmony import */ var _components_categoryLocation__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_components_categoryLocation__WEBPACK_IMPORTED_MODULE_0__);


/***/ }),

/***/ 7:
/*!*********************************************************************!*\
  !*** multi ./assets/src/js/public/modules/all-location-category.js ***!
  \*********************************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

module.exports = __webpack_require__(/*! ./assets/src/js/public/modules/all-location-category.js */"./assets/src/js/public/modules/all-location-category.js");


/***/ })

/******/ });
//# sourceMappingURL=all-location-category.js.map