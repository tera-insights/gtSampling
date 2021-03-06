AdjBernoulli <- function(data, keys, value, outputs, min = 10^5, max = 4 * min,
                         seed = NA) {
  keys <- convert.exprs(substitute(keys))
  value <- convert.exprs(substitute(value))
  inputs <- c(keys, value)

  if (missing(outputs)) {
    outputs <- convert.names(inputs)
    missing <- which(outputs == "")
    exprs <- grokit$expressions[inputs[missing]]
    if (any(bad <- !is.symbols(exprs)))
      stop("no name given for complex inputs:",
           paste("\n\t", lapply(exprs[bad], deparse), collapse = ""))
    else
      outputs[missing] <- as.character(exprs)
  } else {
    if (!is.null(names(inputs)))
      warning("both outputs and named inputs given. outputs used.")
    outputs <- convert.atts(substitute(outputs))
  }

  GLA <- GLA(sampling::Adjustable_Bernoulli, minimum = min, maximum = max,
             increase = 1.5, decrease = 2, seed = seed)
  Aggregate(data, GLA, inputs, outputs)
}
