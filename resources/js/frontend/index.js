/**
 * External dependencies
 */

import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import he from 'he';

const settings = getSetting( 'snappi_gateway_data', {} );

const defaultLabel = __(
    'Snappi Pay Later',
    'snappi-for-woocommerce'
);

const label = decodeEntities( settings.title ) || defaultLabel;
/**
 * Content component
 */
const Content = () => {
    return (
        <div>
            <p>{decodeEntities(settings.description || '')}</p>
        </div>
    );
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    return <PaymentMethodLabel text={ label } />;
};

/**
 * Dummy payment method config object.
 */
const Snappi = {
    name: settings.id,
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};

registerPaymentMethod( Snappi );