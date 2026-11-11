<div class="sales-stock-popup" id="saleStockPopup" data-sale-stock-modal aria-hidden="true">
    <div class="sales-stock-popup-panel" role="dialog" aria-modal="true" aria-labelledby="saleStockPopupTitle">
        <form id="saleStockModalForm" data-sale-stock-modal-form novalidate>
            <input type="hidden" name="asset_stage" value="new_stock">
            <input type="hidden" name="asset_status" value="available_for_sale">
            <input type="hidden" name="asset_name" id="saleStockAssetName">
            <input type="hidden" name="product_id" id="saleStockProductId">
            <div class="sales-stock-popup-head">
                <div>
                    <strong id="saleStockPopupTitle">Add Sale Stock</strong>
                    <span id="saleStockProductSubtitle">Select a sale product first.</span>
                </div>
                <button type="button" class="sales-stock-close" data-close-sale-stock-popup aria-label="Close Add Sale Stock modal">&times;</button>
            </div>
            <div class="sales-stock-modal-body">
                <div class="sales-stock-context" id="saleStockProductContext">
                    <div class="sales-product-thumb" id="saleStockProductThumb">P</div>
                    <div>
                        <strong id="saleStockProductName">Product not selected</strong>
                        <span id="saleStockProductMeta">Choose a product line before adding stock.</span>
                    </div>
                </div>
                <div class="sales-stock-modal-summary" aria-label="Current sale stock summary">
                    <span>Available <strong id="saleStockCurrentAvailable">0</strong></span>
                    <span>Total <strong id="saleStockCurrentTotal">0</strong></span>
                    <span>Sold <strong id="saleStockCurrentSold">0</strong></span>
                </div>
                <div class="sales-stock-modal-error" id="saleStockModalError" hidden></div>
                <div class="sales-stock-modal-grid">
                    <div class="sales-field">
                        <label for="saleStockWarehouseId">Warehouse <span style="color:#dc2626;">*</span></label>
                        <select name="warehouse_id" id="saleStockWarehouseId" required></select>
                    </div>
                    <div class="sales-field">
                        <label for="saleStockSerialNumber">Serial Number <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="serial_number" id="saleStockSerialNumber" autocomplete="off" required>
                    </div>
                    <div class="sales-field">
                        <label for="saleStockBarcodeValue">Barcode</label>
                        <input type="text" name="barcode_value" id="saleStockBarcodeValue" autocomplete="off">
                    </div>
                    <div class="sales-field">
                        <label for="saleStockConditionStatus">Condition</label>
                        <select name="condition_status" id="saleStockConditionStatus">
                            <option value="new">New</option>
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="needs_repair">Needs repair</option>
                            <option value="damaged">Damaged</option>
                        </select>
                    </div>
                    <div class="sales-field">
                        <label for="saleStockPurchaseDate">Purchase Date</label>
                        <input type="date" name="purchase_date" id="saleStockPurchaseDate">
                    </div>
                    <div class="sales-field">
                        <label for="saleStockPurchaseCost">Purchase Cost</label>
                        <input type="number" min="0" step="0.01" name="purchase_cost" id="saleStockPurchaseCost" placeholder="0.00">
                    </div>
                    <div class="sales-field sales-stock-modal-notes">
                        <label for="saleStockNotes">Notes</label>
                        <textarea name="notes" id="saleStockNotes" rows="2" placeholder="Optional stock note"></textarea>
                    </div>
                </div>
            </div>
            <div class="sales-stock-modal-footer">
                <button type="button" class="sales-btn-light" data-close-sale-stock-popup>Cancel</button>
                <button type="submit" class="sales-btn-light" data-sale-stock-submit="add_another">Save & Add Another</button>
                <button type="submit" class="sales-btn" data-sale-stock-submit="save">Save Stock</button>
            </div>
        </form>
    </div>
</div>
