/**
 * Editor side of the "Read more" block (tribune-desk/read-more).
 *
 * Plain script on WordPress globals, no build step. The box itself is rendered by PHP
 * (ReadMore::render); the editor shows the same server render and two settings.
 */
(function (wp) {
    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var RangeControl = wp.components.RangeControl;
    var TextControl = wp.components.TextControl;
    var ServerSideRender = wp.serverSideRender;

    wp.blocks.registerBlockType('tribune-desk/read-more', {
        apiVersion: 2,
        title: __('Read more (Tribune Desk)', 'tribune-desk'),
        description: __('Posts linked to this post by Tribune Desk.', 'tribune-desk'),
        category: 'widgets',
        icon: 'list-view',
        attributes: {
            limit: { type: 'integer', default: 5 },
            title: { type: 'string', default: '' }
        },
        edit: function (props) {
            var attributes = props.attributes;
            return el(
                'div',
                wp.blockEditor.useBlockProps(),
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Settings', 'tribune-desk') },
                        el(TextControl, {
                            label: __('Title', 'tribune-desk'),
                            value: attributes.title,
                            onChange: function (value) { props.setAttributes({ title: value }); }
                        }),
                        el(RangeControl, {
                            label: __('Number of posts', 'tribune-desk'),
                            value: attributes.limit,
                            min: 1,
                            max: 20,
                            onChange: function (value) { props.setAttributes({ limit: value }); }
                        })
                    )
                ),
                el(ServerSideRender, { block: 'tribune-desk/read-more', attributes: attributes })
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp);
