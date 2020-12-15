# magento2-elasticsuite-virtual-category-widget

[Smile-SA Elasticsuite](https://github.com/Smile-SA/elasticsuite) is an amazing tool in the Magento 2 ecosystem and it is unbelievable how much work is being put into. However after few implementations a client wanted a widget on the homepage that has products from a virtual category and it turns out this is not supported by default. So this little module does exactly that - allows widgets to pull products from a virtual category.

At this time it is more of a proof-of-concept. It works only when the widget is configured with one condition only - "Category is X" where X is the ID of the virtual category.

Under the hood the virtual category ID condition is replaced with all of its products skus.
