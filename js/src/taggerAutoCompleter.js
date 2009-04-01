var KronolithTagger = Class.create({
        initialize: function(params)
        {
            this.p = params;
            $(this.p.trigger).observe('keydown', this._onKeyDown.bindAsEventListener(this));
            
            // Make sure the right dom elements are styled correctly.
            $(this.p.container).addClassName('listItem tagACContainer');
            $(this.p.trigger).addClassName('showTag');
            
            // Make sure the p.tags element is hidden
            if (!params.debug) {
                $(this.p.tags).hide();
            }
            
            // Set the updateElement callback
            params.params.updateElement = this._updateElement.bind(this);
            
            // Create the underlaying Autocompleter
            new Ajax.Autocompleter(params.trigger, params.resultsId, params.uri, params.params);
            
            // Prepopulate the tags and the container elements?
            if (this.p.existing) {
               for (var i = 0, l = this.p.existing.length; i < l; i++) {
                    this.addNewTagNode(this.p.existing[i]);
                }
            }
            
        },
        
        _onKeyDown: function(e)
        {
            // Check for a comma
            if (e.keyCode == 188) {
                //Strip off leading commas
                value = $F(this.p.trigger).replace(/^,/, '');
                if (value.length) {
                    if (value.match(/^[^"]?"[^"]+$/)) {
                        // Unclosed quote
                        return;
                    }
                    this.addNewTagNode(value);
                }
                e.stop();
            }          
        },
        
        // Used as the updateElement callback.
        _updateElement: function(item)
        {
            var value = item.collectTextNodesIgnoreClass('informal');
            this.addNewTagNode(value);
        },
        
        addNewTagNode: function(value)
        {
            var newTag = new Element('li', {class: 'listItem tagListItem'}).update(value);
            var x = new Element('img', {class: 'tagRemove', src:this.p.URI_IMG_HORDE + "/delete-small.png"});
            x.observe('click', this._removeTag.bindAsEventListener(this));
            newTag.insert(x);
            $(this.p.container).insert({before: newTag});
            $(this.p.trigger).value = '';

            // Add to hidden input field.
            if ($(this.p.tags).value) {
                $(this.p.tags).value = $(this.p.tags).value + ', ' + value;
            } else {
                $(this.p.tags).value = value;
            }

            // ...and keep the selectedTags array up to date.
            this.p.selectedTags.push(value);
        },
         
        _removeTag: function(e)
        {
            item = Event.element(e).up();
            // The value to remove from the hidden textbox
            var value = item.collectTextNodesIgnoreClass('informal');
            
            for (var x = 0, len = this.p.selectedTags.length; x < len; x++) {
                if (this.p.selectedTags[x] == value) {
                    this.p.selectedTags.splice(x, 1);
                }
            }

            $(this.p.tags).value = this.p.selectedTags.join(',');

            // Nuke the node.
            item.remove();
        }     
});